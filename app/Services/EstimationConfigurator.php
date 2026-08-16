<?php

namespace App\Services;

use App\Models\EstimateValue;
use App\Models\Project;
use App\Models\ProjectEstimation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Configures a project's estimation system (Estimation §5-§8, §10, §20-§23).
 *
 * Every method here obeys one rule, from §25 and §21: **an estimate that a work item is
 * carrying is never destroyed.** Removing a used value archives it; changing the type archives
 * the whole old system rather than converting or deleting it. What a user sees change is which
 * values can be *chosen* from now on.
 */
class EstimationConfigurator
{
    /**
     * Create or replace the project's system (§5, §22).
     *
     * Replacing does NOT convert anything — §23 is explicit that `5 points` has no honest
     * translation into `Medium`, so the old values are archived and the new ones created
     * beside them. Work items keep pointing at whatever they were given, and their history
     * still reads correctly.
     *
     * @param  array<int, array<string, mixed>>|null  $values  custom values, or null for the template's
     */
    public function configure(Project $project, User $actor, string $type, string $template, ?array $values = null): ProjectEstimation
    {
        $catalog = config("projects.estimate_types.{$type}");

        if (! $catalog || ! isset($catalog['templates'][$template])) {
            throw ValidationException::withMessages(['type' => 'That estimation system is not available.']);
        }

        return DB::transaction(function () use ($project, $actor, $type, $template, $values, $catalog) {
            $estimation = ProjectEstimation::query()->where('project_id', $project->id)->first();

            if ($estimation) {
                // §22/§23: the previous system's values go inactive, not away.
                $estimation->values()->update(['active' => false]);
                $estimation->forceFill(['type' => $type, 'template' => $template])->save();
            } else {
                $estimation = ProjectEstimation::create([
                    'project_id' => $project->id,
                    'type' => $type,
                    'template' => $template,
                    'created_by' => $actor->id,
                ]);
            }

            $this->createValues($estimation, $values ?? $this->templateValues($type, $catalog['templates'][$template]));

            return $estimation->fresh('values');
        });
    }

    /** Add one value to the active system (§10/§20). */
    public function addValue(ProjectEstimation $estimation, array $value): EstimateValue
    {
        $this->assertRoom($estimation);

        $next = (int) $estimation->values()->max('sort_order') + 1;

        return $this->makeValue($estimation, $value, $next);
    }

    /** Rename a value, or change what it is worth (§20). */
    public function renameValue(EstimateValue $value, array $changes): EstimateValue
    {
        $value->fill(array_intersect_key(
            $changes,
            array_flip(['label', 'numeric_value', 'duration_minutes', 'capacity_hours']),
        ))->save();

        return $value->fresh();
    }

    /**
     * Remove a value (§21).
     *
     * Archived rather than deleted whenever a work item is carrying it — that work item's
     * estimate is historical data, and §25 forbids destroying it. A value nothing uses is
     * genuinely deleted, because there is no history to protect and leaving it archived would
     * clutter the editor forever.
     *
     * @return bool true if it was archived, false if it was deleted outright
     */
    public function removeValue(EstimateValue $value): bool
    {
        $inUse = $value->workItems()->count() > 0;

        if ($inUse) {
            $value->forceFill(['active' => false])->save();

            return true;
        }

        $value->delete();

        return false;
    }

    /** Restore an archived value so it can be chosen again. */
    public function restoreValue(EstimateValue $value): void
    {
        $value->forceFill(['active' => true])->save();
    }

    /**
     * Reorder the active values (§10/§20).
     *
     * The order is stored rather than derived: a category runs XS → S → M → L → XL, which is
     * neither alphabetical nor numeric, and §19 wants exactly that order respected.
     *
     * @param  array<int, int>  $orderedIds
     */
    public function reorder(ProjectEstimation $estimation, array $orderedIds): void
    {
        $owned = $estimation->values()->pluck('id')->all();

        DB::transaction(function () use ($orderedIds, $owned) {
            foreach (array_values($orderedIds) as $position => $id) {
                // Ids from another project's system are ignored rather than trusted.
                if (! in_array((int) $id, $owned, true)) {
                    continue;
                }

                EstimateValue::query()->whereKey($id)->update(['sort_order' => $position + 1]);
            }
        });
    }

    /** How many work items carry a value — what §21's warning has to name before removing it. */
    public function usageCount(EstimateValue $value): int
    {
        return $value->workItems()->count();
    }

    /** @param array<int, array<string, mixed>> $values */
    private function createValues(ProjectEstimation $estimation, array $values): void
    {
        foreach (array_values($values) as $position => $value) {
            $this->makeValue($estimation, $value, $position + 1);
        }
    }

    private function makeValue(ProjectEstimation $estimation, array $value, int $sortOrder): EstimateValue
    {
        $label = trim((string) ($value['label'] ?? ''));

        if ($label === '') {
            throw ValidationException::withMessages(['label' => 'Give the estimate a label.']);
        }

        return EstimateValue::create([
            'project_estimation_id' => $estimation->id,
            'label' => $label,
            // Only the column that means something for this type is filled. A category fills
            // neither, which is what makes it uncountable rather than zero.
            'numeric_value' => $estimation->type === ProjectEstimation::TYPE_POINTS
                ? ($value['numeric_value'] ?? (is_numeric($label) ? (float) $label : null))
                : null,
            'duration_minutes' => $estimation->type === ProjectEstimation::TYPE_TIME
                ? ($value['duration_minutes'] ?? null)
                : null,
            // What this estimate is worth in working hours (work-capacity CAP-D1). Points and
            // categories carry it; a time value derives its own from duration_minutes, so
            // storing a second figure beside it would be a second answer to one question.
            'capacity_hours' => $estimation->type === ProjectEstimation::TYPE_TIME
                ? null
                : ($value['capacity_hours'] ?? null),
            'sort_order' => $sortOrder,
            'active' => true,
        ]);
    }

    /**
     * A template's seed values, in the shape makeValue() wants.
     *
     * @return array<int, array<string, mixed>>
     */
    private function templateValues(string $type, array $template): array
    {
        $out = [];

        foreach ($template['values'] as $key => $value) {
            $out[] = match ($type) {
                // Time templates are label => minutes, so the key carries the reading.
                ProjectEstimation::TYPE_TIME => ['label' => (string) $key, 'duration_minutes' => (int) $value],
                ProjectEstimation::TYPE_POINTS => ['label' => (string) $value, 'numeric_value' => (float) $value],
                default => ['label' => (string) $value],
            };
        }

        return $out;
    }

    private function assertRoom(ProjectEstimation $estimation): void
    {
        $max = (int) config('projects.estimate_values_max');

        if ($estimation->activeValues()->count() >= $max) {
            throw ValidationException::withMessages([
                'label' => "An estimation system can have at most {$max} values.",
            ]);
        }
    }
}
