<?php

namespace App\Http\Controllers\Project;

use App\Models\Project;
use App\Models\ProjectItemState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Project → Settings → States (PRJ-043): project-scoped work-item states. The default state
 * and the last remaining state are protected from deletion.
 */
class ProjectStateController extends ManagesProjectController
{
    public function store(Request $request, Project $project): JsonResponse
    {
        $this->guardManage($project);
        $data = $this->validated($request);

        DB::transaction(function () use ($project, $data, $request) {
            $state = ProjectItemState::create([
                'project_id' => $project->id,
                'name' => $data['name'], 'color' => $data['color'],
                'description' => $data['description'] ?? null, 'group' => $data['group'],
                'is_default' => false, 'position' => $this->nextPosition(ProjectItemState::class, $project->id),
            ]);
            $this->maybeDefault($project, $state, $request->boolean('is_default'));
        });

        return response()->json(['ok' => true, 'states' => $this->states($project)]);
    }

    public function update(Request $request, Project $project, ProjectItemState $state): JsonResponse
    {
        $this->guardManage($project);
        abort_unless($state->project_id === $project->id, 404);
        $data = $this->validated($request);

        DB::transaction(function () use ($project, $state, $data, $request) {
            $state->forceFill([
                'name' => $data['name'], 'color' => $data['color'],
                'description' => $data['description'] ?? null, 'group' => $data['group'],
            ])->save();
            $this->maybeDefault($project, $state, $request->boolean('is_default'));
        });

        return response()->json(['ok' => true, 'states' => $this->states($project)]);
    }

    public function destroy(Project $project, ProjectItemState $state): JsonResponse
    {
        $this->guardManage($project);
        abort_unless($state->project_id === $project->id, 404);
        abort_if(ProjectItemState::query()->where('project_id', $project->id)->count() <= 1, 422, 'A project must keep at least one state.');

        DB::transaction(function () use ($project, $state) {
            $wasDefault = $state->is_default;
            $state->delete();
            if ($wasDefault) {
                $next = ProjectItemState::query()->where('project_id', $project->id)->orderBy('position')->first();
                $next?->forceFill(['is_default' => true])->save();
            }
        });

        return response()->json(['ok' => true, 'states' => $this->states($project)]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'color' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'description' => ['nullable', 'string', 'max:255'],
            'group' => ['required', Rule::in(array_keys(config('projects.state_groups')))],
        ]);
    }

    private function maybeDefault(Project $project, ProjectItemState $state, bool $makeDefault): void
    {
        if (! $makeDefault) {
            return;
        }
        ProjectItemState::query()->where('project_id', $project->id)->where('id', '!=', $state->id)->update(['is_default' => false]);
        $state->forceFill(['is_default' => true])->save();
    }

    private function states(Project $project): array
    {
        return $project->states()->orderBy('position')->get()
            ->map(fn (ProjectItemState $s) => ['id' => $s->id, 'name' => $s->name, 'color' => $s->color, 'group' => $s->group, 'is_default' => $s->is_default])->all();
    }
}
