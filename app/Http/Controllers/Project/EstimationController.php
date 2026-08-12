<?php

namespace App\Http\Controllers\Project;

use App\Models\EstimateValue;
use App\Models\Project;
use App\Models\ProjectEstimation;
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
        ]);

        $this->configurator->renameValue($value, $data);

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
                'sort_order' => $v->sort_order,
                'active' => $v->active,
                // §21's warning has to name a number before anything is removed.
                'in_use' => $v->workItems()->count(),
            ])->values()->all(),
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
