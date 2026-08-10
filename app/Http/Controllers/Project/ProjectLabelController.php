<?php

namespace App\Http\Controllers\Project;

use App\Models\Project;
use App\Models\ProjectItemLabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Project → Settings → Labels (PRJ-043): project-scoped labels. */
class ProjectLabelController extends ManagesProjectController
{
    public function store(Request $request, Project $project): JsonResponse
    {
        $this->guardManage($project);
        $data = $this->validated($request);

        ProjectItemLabel::create([
            'project_id' => $project->id, 'name' => $data['name'], 'color' => $data['color'],
            'position' => $this->nextPosition(ProjectItemLabel::class, $project->id),
        ]);

        return response()->json(['ok' => true, 'labels' => $this->labels($project)]);
    }

    public function update(Request $request, Project $project, ProjectItemLabel $label): JsonResponse
    {
        $this->guardManage($project);
        abort_unless($label->project_id === $project->id, 404);
        $label->forceFill($this->validated($request))->save();

        return response()->json(['ok' => true, 'labels' => $this->labels($project)]);
    }

    public function destroy(Project $project, ProjectItemLabel $label): JsonResponse
    {
        $this->guardManage($project);
        abort_unless($label->project_id === $project->id, 404);
        $label->delete();

        return response()->json(['ok' => true, 'labels' => $this->labels($project)]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'color' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);
    }

    private function labels(Project $project): array
    {
        return $project->labels()->orderBy('position')->get()
            ->map(fn (ProjectItemLabel $l) => ['id' => $l->id, 'name' => $l->name, 'color' => $l->color])->all();
    }
}
