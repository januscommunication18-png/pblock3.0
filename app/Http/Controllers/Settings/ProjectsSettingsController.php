<?php

namespace App\Http\Controllers\Settings;

use App\Http\Requests\Settings\LabelRequest;
use App\Http\Requests\Settings\ProjectStateRequest;
use App\Models\ProjectLabel;
use App\Models\ProjectPriority;
use App\Models\ProjectState;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Features > Projects (spec §6). Workspace-wide project states + labels. States are seeded
 * once (by WorkspaceSettingsManager); the default state and the final remaining state are
 * protected from deletion (SET-PROJ-003).
 */
class ProjectsSettingsController extends SettingsController
{
    /** GET /settings/projects */
    public function show(): View
    {
        $this->guardManage();
        $settings = $this->settings();

        return $this->page('projects', [
            'enabled' => $settings->project_states_enabled,
            'states' => $this->states(),
            'labels' => $this->labels(),
            'priorities' => $this->priorities(),
            'groups' => config('settings.state_groups'),
            'color' => $this->colorMeta(),
            'endpoints' => [
                'toggle' => route('settings.projects.toggle'),
                'states' => route('settings.projects.states.store'),
                'state' => route('settings.projects.states.update', ['state' => '__ID__']),
                'labels' => route('settings.projects.labels.store'),
                'label' => route('settings.projects.labels.update', ['label' => '__ID__']),
                'priorities' => route('settings.projects.priorities.store'),
                'priority' => route('settings.projects.priorities.update', ['priority' => '__ID__']),
            ],
        ]);
    }

    /** POST /settings/projects/toggle */
    public function toggle(): JsonResponse
    {
        $this->guardManage();
        $settings = $this->setFeature('project_states_enabled', request()->boolean('enabled'));

        return response()->json(['ok' => true, 'enabled' => $settings->project_states_enabled]);
    }

    /** POST /settings/projects/states */
    public function storeState(ProjectStateRequest $request): JsonResponse
    {
        $this->guardManage();

        DB::transaction(function () use ($request) {
            $state = ProjectState::create([
                'name' => $request->validated('name'),
                'color' => $request->validated('color'),
                'description' => $request->validated('description'),
                'group' => $request->validated('group'),
                'is_default' => false,
                'position' => $this->nextPosition(ProjectState::class),
            ]);
            $this->maybeMakeDefault($state, $request->boolean('is_default'));
        });

        return response()->json(['ok' => true, 'states' => $this->states()]);
    }

    /** PATCH /settings/projects/states/{state} */
    public function updateState(ProjectStateRequest $request, ProjectState $state): JsonResponse
    {
        $this->guardManage();

        DB::transaction(function () use ($request, $state) {
            $state->forceFill([
                'name' => $request->validated('name'),
                'color' => $request->validated('color'),
                'description' => $request->validated('description'),
                'group' => $request->validated('group'),
            ])->save();
            $this->maybeMakeDefault($state, $request->boolean('is_default'));
        });

        return response()->json(['ok' => true, 'states' => $this->states()]);
    }

    /** DELETE /settings/projects/states/{state} */
    public function destroyState(ProjectState $state): JsonResponse
    {
        $this->guardManage();

        // Never delete the last remaining state (SET-PROJ-003).
        abort_if(ProjectState::query()->count() <= 1, 422, 'A workspace must keep at least one project state.');

        DB::transaction(function () use ($state) {
            $wasDefault = $state->is_default;
            $state->delete();

            // Reassign the default flag if we removed the default state.
            if ($wasDefault) {
                $next = ProjectState::query()->orderBy('position')->first();
                $next?->forceFill(['is_default' => true])->save();
            }
        });

        return response()->json(['ok' => true, 'states' => $this->states()]);
    }

    /** POST /settings/projects/labels */
    public function storeLabel(LabelRequest $request): JsonResponse
    {
        $this->guardManage();

        ProjectLabel::create([
            'name' => $request->validated('name'),
            'color' => $request->validated('color'),
            'position' => $this->nextPosition(ProjectLabel::class),
        ]);

        return response()->json(['ok' => true, 'labels' => $this->labels()]);
    }

    /** PATCH /settings/projects/labels/{label} */
    public function updateLabel(LabelRequest $request, ProjectLabel $label): JsonResponse
    {
        $this->guardManage();
        $label->forceFill($request->only('name', 'color'))->save();

        return response()->json(['ok' => true, 'labels' => $this->labels()]);
    }

    /** DELETE /settings/projects/labels/{label} */
    public function destroyLabel(ProjectLabel $label): JsonResponse
    {
        $this->guardManage();
        $label->delete();

        return response()->json(['ok' => true, 'labels' => $this->labels()]);
    }

    /** POST /settings/projects/priorities */
    public function storePriority(LabelRequest $request): JsonResponse
    {
        $this->guardManage();

        ProjectPriority::create([
            'name' => $request->validated('name'),
            'color' => $request->validated('color'),
            'position' => $this->nextPosition(ProjectPriority::class),
        ]);

        return response()->json(['ok' => true, 'priorities' => $this->priorities()]);
    }

    /** PATCH /settings/projects/priorities/{priority} — rename / recolor */
    public function updatePriority(LabelRequest $request, ProjectPriority $priority): JsonResponse
    {
        $this->guardManage();
        $priority->forceFill($request->only('name', 'color'))->save();

        return response()->json(['ok' => true, 'priorities' => $this->priorities()]);
    }

    /** DELETE /settings/projects/priorities/{priority} */
    public function destroyPriority(ProjectPriority $priority): JsonResponse
    {
        $this->guardManage();
        $priority->delete();

        return response()->json(['ok' => true, 'priorities' => $this->priorities()]);
    }

    /** Ensure exactly one default state when marking one as default. */
    private function maybeMakeDefault(ProjectState $state, bool $makeDefault): void
    {
        if (! $makeDefault) {
            return;
        }
        ProjectState::query()->where('id', '!=', $state->id)->update(['is_default' => false]);
        $state->forceFill(['is_default' => true])->save();
    }

    private function states(): array
    {
        return ProjectState::query()->orderBy('position')->get()
            ->map(fn (ProjectState $s) => [
                'id' => $s->id, 'name' => $s->name, 'color' => $s->color,
                'description' => $s->description, 'group' => $s->group, 'is_default' => $s->is_default,
            ])->all();
    }

    private function labels(): array
    {
        return ProjectLabel::query()->orderBy('position')->get()
            ->map(fn (ProjectLabel $l) => ['id' => $l->id, 'name' => $l->name, 'color' => $l->color])->all();
    }

    /**
     * Workspace project priorities. Seeds the defaults on first read so existing workspaces
     * (whose settings were provisioned before priorities existed) still get the list.
     *
     * @return array<int, array<string, mixed>>
     */
    private function priorities(): array
    {
        // Guard so the Settings page still loads if the migration hasn't been run yet.
        if (! Schema::hasTable('project_priorities')) {
            return [];
        }

        $this->settingsManager->seedDefaultProjectPriorities();

        return ProjectPriority::query()->orderBy('position')->get()
            ->map(fn (ProjectPriority $p) => ['id' => $p->id, 'name' => $p->name, 'color' => $p->color])->all();
    }
}
