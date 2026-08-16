<?php

namespace App\Http\Controllers\Project;

use App\Models\EstimateValue;
use App\Models\Project;
use App\Models\ProjectEstimation;
use App\Services\Capacity\CapacitySettings;
use App\Services\Capacity\CapacitySnapshotWriter;
use App\Services\EstimationConfigurator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Project Settings → Estimation (Estimation §3, §10, §20-§23).
 *
 * §30 gives every action here to the Project Admin only, which is ProjectPolicy@manage — the
 * same gate the rest of Project Settings uses. Assigning an estimate to a work item is a
 * different question and a lower bar (§30's Contributor row); that lives with the work item.
 */
class EstimationController extends ManagesProjectController
{
    public function __construct(private readonly EstimationConfigurator $configurator) {}

    /**
     * POST /projects/{project}/settings/estimation — create or replace the system (§5, §22).
     *
     * §23: nothing is converted. The previous values are archived, the new ones created beside
     * them, and every work item keeps the estimate it was given.
     */
    public function configure(Request $request, Project $project): JsonResponse
    {
        $this->guardManage($project);

        $data = $request->validate([
            'type' => ['required', 'string'],
            'template' => ['required', 'string'],
            'values' => ['sometimes', 'array', 'max:'.config('projects.estimate_values_max')],
            'values.*.label' => ['required', 'string', 'max:'.config('projects.estimate_label_max')],
            'values.*.numeric_value' => ['nullable', 'numeric'],
            'values.*.duration_minutes' => ['nullable', 'integer', 'min:1'],
        ]);

        $estimation = $this->configurator->configure(
            $project, Auth::user(), $data['type'], $data['template'], $data['values'] ?? null,
        );

        return response()->json([
            'ok' => true,
            'estimation' => $this->card($project, $estimation),
            'message' => 'Estimation system saved.',
        ]);
    }

    /** POST /projects/{project}/settings/estimation/values — add one value (§10/§20). */
    public function storeValue(Request $request, Project $project): JsonResponse
    {
        $this->guardManage($project);
        $estimation = $this->estimation($project);

        $data = $request->validate([
            'label' => ['required', 'string', 'max:'.config('projects.estimate_label_max')],
            'numeric_value' => ['nullable', 'numeric'],
            'duration_minutes' => ['nullable', 'integer', 'min:1'],
            'capacity_hours' => ['nullable', 'numeric', 'min:0', 'max:9999'],
        ]);

        $this->configurator->addValue($estimation, $data);

        return response()->json([
            'ok' => true,
            'estimation' => $this->card($project, $estimation->fresh()),
            'message' => 'Estimate added.',
        ]);
    }

    /** PATCH /projects/{project}/settings/estimation/values/{value} — rename (§20). */
    public function updateValue(Request $request, Project $project, EstimateValue $value): JsonResponse
    {
        $this->guardManage($project);
        $estimation = $this->estimation($project);
        abort_unless((int) $value->project_estimation_id === (int) $estimation->id, 404);

        $data = $request->validate([
            'label' => ['sometimes', 'required', 'string', 'max:'.config('projects.estimate_label_max')],
            'numeric_value' => ['sometimes', 'nullable', 'numeric'],
            'duration_minutes' => ['sometimes', 'nullable', 'integer', 'min:1'],
            // Nullable is the point: clearing the field means "not mapped", which capacity
            // treats as unestimated rather than as zero hours (CAP-D2).
            'capacity_hours' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999'],
        ]);

        $this->configurator->renameValue($value, $data);

        // Open work items carrying this value are re-planned with the new figure; completed
        // ones keep what they were planned with, so last quarter's report does not move
        // (CAP-D3 / §47).
        if (array_key_exists('capacity_hours', $data)) {
            app(CapacitySnapshotWriter::class)->afterValueChanged($value->fresh());
        }

        return response()->json([
            'ok' => true,
            'estimation' => $this->card($project, $estimation->fresh()),
            'message' => 'Estimate updated.',
        ]);
    }

    /**
     * DELETE /projects/{project}/settings/estimation/values/{value} (§21).
     *
     * Archived when work items are carrying it, deleted when nothing is. The response says
     * which happened, because the two mean different things to the person who asked.
     */
    public function destroyValue(Project $project, EstimateValue $value): JsonResponse
    {
        $this->guardManage($project);
        $estimation = $this->estimation($project);
        abort_unless((int) $value->project_estimation_id === (int) $estimation->id, 404);

        $archived = $this->configurator->removeValue($value);

        return response()->json([
            'ok' => true,
            'archived' => $archived,
            'estimation' => $this->card($project, $estimation->fresh()),
            'message' => $archived
                ? "\"{$value->label}\" can no longer be assigned. Work items already using it keep it."
                : 'Estimate removed.',
        ]);
    }

    /** POST /projects/{project}/settings/estimation/values/{value}/restore — un-archive. */
    public function restoreValue(Project $project, EstimateValue $value): JsonResponse
    {
        $this->guardManage($project);
        $estimation = $this->estimation($project);
        abort_unless((int) $value->project_estimation_id === (int) $estimation->id, 404);

        $this->configurator->restoreValue($value);

        return response()->json([
            'ok' => true,
            'estimation' => $this->card($project, $estimation->fresh()),
            'message' => 'Estimate restored.',
        ]);
    }

    /** POST /projects/{project}/settings/estimation/reorder (§10/§20). */
    public function reorder(Request $request, Project $project): JsonResponse
    {
        $this->guardManage($project);
        $estimation = $this->estimation($project);

        $ids = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ])['ids'];

        $this->configurator->reorder($estimation, $ids);

        return response()->json([
            'ok' => true,
            'estimation' => $this->card($project, $estimation->fresh()),
            'message' => 'Order saved.',
        ]);
    }

    /**
     * The settings screen's payload for one project.
     *
     * Static so ProjectSettingsController can build the same shape without going through an
     * HTTP round trip — one definition of what this screen is looking at.
     *
     * @return array<string, mixed>
     */
    public static function payload(Project $project): array
    {
        $estimation = ProjectEstimation::query()->where('project_id', $project->id)->first();

        return [
            'enabled' => $project->featureEnabled('estimates'),
            'estimation' => $estimation ? self::describe($estimation) : null,
            'types' => collect(config('projects.estimate_types'))
                ->map(fn (array $meta, string $key) => [
                    'key' => $key,
                    'label' => $meta['label'],
                    'description' => $meta['description'],
                    'templates' => collect($meta['templates'])
                        ->map(fn (array $t, string $tk) => [
                            'key' => $tk,
                            'label' => $t['label'],
                            // Shown as a preview before anything is committed, so the choice
                            // is made on the actual values rather than on a name.
                            'preview' => array_map('strval', array_keys(
                                array_is_list($t['values']) ? array_flip(array_map('strval', $t['values'])) : $t['values']
                            )),
                        ])->values()->all(),
                ])->values()->all(),
            // Work Capacity context (docs/features/work-capacity.md §7). Sent whether or not
            // capacity is on, because the screen has to know which of the two it is: with it
            // off there is nothing to map estimates INTO, and an hours column would be asking
            // for a number that feeds nothing.
            'capacity' => self::capacityContext(),
            'labelMax' => (int) config('projects.estimate_label_max'),
            'valuesMax' => (int) config('projects.estimate_values_max'),
            'endpoints' => [
                'configure' => route('projects.settings.estimation.configure', $project),
                'values' => route('projects.settings.estimation.values.store', $project),
                'value' => route('projects.settings.estimation.values.update', ['project' => $project->id, 'value' => '__ID__']),
                'restore' => route('projects.settings.estimation.values.restore', ['project' => $project->id, 'value' => '__ID__']),
                'reorder' => route('projects.settings.estimation.reorder', $project),
                'toggle' => route('projects.settings.features.toggle', $project),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function card(Project $project, ProjectEstimation $estimation): array
    {
        return self::describe($estimation);
    }

    /** @return array<string, mixed> */
    private static function describe(ProjectEstimation $estimation): array
    {
        return [
            'id' => $estimation->id,
            'type' => $estimation->type,
            'template' => $estimation->template,
            'label' => $estimation->label(),
            'values' => $estimation->values()->get()->map(fn (EstimateValue $v) => [
                'id' => $v->id,
                'label' => $v->label,
                'numeric_value' => $v->numeric_value !== null ? (float) $v->numeric_value : null,
                'duration_minutes' => $v->duration_minutes,
                // What the row shows in its hours field. `capacity_hours` is what is STORED
                // and editable; `hours` is what this value is actually worth — the two differ
                // for a time estimate, which derives its hours from its own duration.
                'capacity_hours' => $v->capacity_hours !== null ? (float) $v->capacity_hours : null,
                'hours' => $v->capacityHours(),
                'sort_order' => $v->sort_order,
                'active' => $v->active,
                // §21's warning has to name a number before anything is removed.
                'in_use' => $v->workItems()->count(),
            ])->values()->all(),
        ];
    }

    /**
     * The workspace's working week, for the header on this screen.
     *
     * Read through CapacitySettings rather than the settings row, so the day and week totals
     * here are produced by the same code the Team Capacity report reads — a screen that says
     * "40h/week" while the report measures against something else is worse than one that says
     * nothing.
     *
     * @return array<string, mixed>
     */
    private static function capacityContext(): array
    {
        $capacity = app(CapacitySettings::class);

        return [
            'enabled' => $capacity->enabled(),
            'hoursPerDay' => $capacity->hoursPerDay(),
            'weeklyHours' => $capacity->weeklyHours(),
            'workingDays' => count($capacity->workingDays()),
            'settingsUrl' => route('settings.work-capacity'),
        ];
    }

    private function estimation(Project $project): ProjectEstimation
    {
        $estimation = ProjectEstimation::query()->where('project_id', $project->id)->first();

        // §5: values only mean something inside a configured system.
        abort_unless($estimation !== null, 422, 'Choose an estimation system first.');

        return $estimation;
    }
}
