<?php

namespace App\Services;

use App\Models\Cycle;
use App\Models\Epic;
use App\Models\EstimateValue;
use App\Models\Module;
use App\Models\Project;
use App\Models\ProjectEstimation;
use App\Models\ProjectItemLabel;
use App\Models\ProjectPage;
use App\Models\ProjectView;

/**
 * What state is an optional project feature in (Feature Disable §10)?
 *
 * Three states, not two — the distinction is what stops a disabled feature from either losing
 * its history or cluttering a project that never used it:
 *
 *  - **enabled** — normal functionality.
 *  - **disabled_history** — switched off, but records exist. Everything stays readable; nothing
 *    can be created, changed or removed. The nav item stays visible, marked Disabled, so the
 *    history is reachable.
 *  - **disabled_unused** — switched off and nothing was ever created. The feature disappears
 *    from the project entirely; there is nothing to preserve and no reason to show a door that
 *    opens onto an empty room.
 *
 * §9's data integrity rule is the reason this class exists at all: disabling must never be
 * treated as a delete, so "is it on?" is not enough of a question. Everything that decides
 * whether to hide, freeze, or allow asks here, and gets one consistent answer for Epics,
 * Modules and Cycles alike.
 */
class ProjectFeatureState
{
    public const ENABLED = 'enabled';

    public const DISABLED_HISTORY = 'disabled_history';

    public const DISABLED_UNUSED = 'disabled_unused';

    /**
     * The models that hold each feature's records. A feature absent from this map has no
     * records of its own, so it can only ever be enabled or disabled_unused.
     *
     * @var array<string, class-string>
     */
    private const RECORDS = [
        'epics' => Epic::class,
        'modules' => Module::class,
        'cycles' => Cycle::class,
        'labels' => ProjectItemLabel::class,
        'pages' => ProjectPage::class,
        // Views §4.2: existing Views stay stored when the feature is switched off, so a
        // project that ever built one keeps its tab and reopens it read-only.
        'views' => ProjectView::class,
    ];

    /**
     * Deliberately NOT memoised.
     *
     * A cache here answers from whenever it was filled, and this value changes the moment
     * somebody flips the toggle — a stale "disabled" would hide a tab the user just turned on.
     * The cost of being right is one COUNT, and only for a feature that is already off:
     * `resolve()` returns before touching the database whenever the feature is enabled.
     */
    public function state(Project $project, string $feature): string
    {
        return $this->resolve($project, $feature);
    }

    public function enabled(Project $project, string $feature): bool
    {
        return $this->state($project, $feature) === self::ENABLED;
    }

    /**
     * Readable but frozen: the page loads, records show, nothing can change.
     *
     * This is the state §1 describes — "display existing assignments as read-only where
     * historical context is needed".
     */
    public function readOnly(Project $project, string $feature): bool
    {
        return $this->state($project, $feature) === self::DISABLED_HISTORY;
    }

    /** Should the feature appear anywhere outside Project Settings (§5/§10)? */
    public function visible(Project $project, string $feature): bool
    {
        return $this->state($project, $feature) !== self::DISABLED_UNUSED;
    }

    /**
     * How many records the feature has in this project.
     *
     * Live records only — a soft-deleted epic or module is not history anyone can reach, and
     * counting it would keep a nav item alive for a feature with nothing behind it.
     */
    public function records(Project $project, string $feature): int
    {
        if ($feature === 'estimates') {
            return $this->estimateValueCount($project);
        }

        $model = self::RECORDS[$feature] ?? null;

        if ($model === null) {
            return 0;
        }

        return $model::query()->where('project_id', $project->id)->count();
    }

    /**
     * Estimation's records are its configured values, which hang off the system rather than
     * off the project — so it cannot use the map above. Counting the system itself would be
     * wrong: a project that switched estimation on and never configured anything has nothing
     * to preserve, and should get the clean UI (§10 of the disable rules).
     */
    private function estimateValueCount(Project $project): int
    {
        $estimationId = ProjectEstimation::query()->where('project_id', $project->id)->value('id');

        return $estimationId
            ? EstimateValue::query()->where('project_estimation_id', $estimationId)->count()
            : 0;
    }

    /**
     * The confirmation a user sees before switching a feature off (§7).
     *
     * Built from the feature's own label rather than stored six times: §7 gives the wording for
     * Modules and says the same pattern applies to Epics and Cycles, and three hand-written
     * copies of one sentence is three chances for them to drift apart.
     *
     * @return array<string, mixed>
     */
    public function disableConfirmation(Project $project, string $feature): array
    {
        $meta = config("projects.features.{$feature}");
        $plural = $meta['label'] ?? ucfirst($feature);
        $singular = $meta['singular'] ?? rtrim($plural, 's');
        $count = $this->records($project, $feature);

        return [
            'confirm' => true,
            'feature' => $feature,
            'count' => $count,
            'title' => "Disable {$plural}?",
            'intro' => "Existing {$plural} and Work Item associations will be preserved.",
            'bullets' => [
                "New {$plural} cannot be created.",
                "Existing {$plural} cannot be modified.",
                "Work Items cannot be assigned to {$plural}.",
                "Existing {$singular} assignments remain available as read-only information.",
            ],
            'footer' => "You can enable {$plural} again at any time without losing data.",
            'confirmLabel' => "Disable {$plural}",
        ];
    }

    /** The read-only notice shown on a disabled feature's own page (§3/§4). */
    public function disabledNotice(string $feature): string
    {
        $meta = config("projects.features.{$feature}");
        $plural = $meta['label'] ?? ucfirst($feature);

        return "{$plural} are disabled for this project. Enable {$plural} in Project Settings to create or manage {$plural}.";
    }

    private function resolve(Project $project, string $feature): string
    {
        if ($project->featureEnabled($feature)) {
            return self::ENABLED;
        }

        return $this->records($project, $feature) > 0
            ? self::DISABLED_HISTORY
            : self::DISABLED_UNUSED;
    }
}
