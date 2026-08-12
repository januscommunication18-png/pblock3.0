<?php

namespace App\Http\Controllers\Project;

use App\Models\Project;
use App\Models\ProjectItemLabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Project → Settings → Labels (Project-Level Labels §6-§12).
 *
 * §25 gives label management to the Project Admin, which is ProjectPolicy@manage — the same
 * gate the rest of Project Settings uses. Assigning a label to a work item is a different
 * question and a lower bar (§25's Contributor row); that lives with the work item.
 *
 * Every write also requires the feature to be ON: §3 disables the creation of additional
 * labels while it is off, and hiding the button without refusing the request would leave the
 * rule enforced only in the UI.
 */
class ProjectLabelController extends ManagesProjectController
{
    public function store(Request $request, Project $project): JsonResponse
    {
        $this->guardWrite($project);
        $data = $this->validated($request, $project);

        ProjectItemLabel::create([
            'project_id' => $project->id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'color' => $data['color'],
            'position' => $this->nextPosition(ProjectItemLabel::class, $project->id),
        ]);

        return response()->json(['ok' => true, 'labels' => $this->labels($project)]);
    }

    /**
     * §9: renaming updates the label everywhere it is assigned.
     *
     * Nothing extra is needed for that — the work item chips read the label by relation, so
     * one row changes and every display follows. Copying the name onto the pivot would have
     * been the version that needed a migration to fix.
     */
    public function update(Request $request, Project $project, ProjectItemLabel $label): JsonResponse
    {
        $this->guardWrite($project);
        abort_unless($label->project_id === $project->id, 404);

        $label->forceFill($this->validated($request, $project, $label))->save();

        return response()->json(['ok' => true, 'labels' => $this->labels($project)]);
    }

    /**
     * §10: deleting removes the label from its work items — and nothing else.
     *
     * The pivot rows go with it through the FK's cascade, so the work items survive exactly as
     * §10 requires. §11 offers archiving as the gentler option when the categorization is
     * worth keeping; that is `archive` below, and it is what the UI steers toward.
     */
    public function destroy(Project $project, ProjectItemLabel $label): JsonResponse
    {
        $this->guardWrite($project);
        abort_unless($label->project_id === $project->id, 404);

        $used = $label->workItems()->count();
        $label->delete();

        return response()->json([
            'ok' => true,
            'labels' => $this->labels($project),
            'message' => $used === 0
                ? 'Label deleted.'
                : ($used === 1 ? 'Label deleted and removed from 1 work item.' : "Label deleted and removed from {$used} work items."),
        ]);
    }

    /** §11: Active → Archived. Out of the picker, still on the work items using it. */
    public function archive(Project $project, ProjectItemLabel $label): JsonResponse
    {
        $this->guardWrite($project);
        abort_unless($label->project_id === $project->id, 404);

        $label->forceFill(['archived_at' => $label->isArchived() ? null : now()])->save();

        return response()->json([
            'ok' => true,
            'labels' => $this->labels($project),
            'message' => $label->isArchived() ? 'Label archived.' : 'Label restored.',
        ]);
    }

    /**
     * §8's validation.
     *
     * The duplicate check ignores the label being edited, so saving a colour change without
     * touching the name is not refused for clashing with itself.
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request, Project $project, ?ProjectItemLabel $label = null): array
    {
        $request->merge([
            // §8: leading and trailing spaces are removed before anything else looks at it.
            'name' => trim((string) $request->input('name')),
            'description' => $request->filled('description') ? trim((string) $request->input('description')) : null,
        ]);

        return $request->validate([
            'name' => [
                'required', 'string', 'max:'.config('projects.label_name_max'),
                // §8/§20: unique within THIS project, and free to repeat in another.
                Rule::unique('project_item_labels', 'name')
                    ->where('project_id', $project->id)
                    ->ignore($label?->id),
            ],
            'description' => ['nullable', 'string', 'max:'.config('projects.label_description_max')],
            'color' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ], [
            'name.unique' => 'A label with that name already exists in this project.',
        ]);
    }

    /** Project Admin, and only while the feature is on (§3/§25). */
    private function guardWrite(Project $project): void
    {
        $this->guardManage($project);
        abort_unless($project->featureEnabled('labels'), 403, 'Labels are disabled for this project.');
    }

    /**
     * The management list (§12/§24) — including archived ones, which the screen shows apart.
     *
     * @return array<int, array<string, mixed>>
     */
    private function labels(Project $project): array
    {
        return $project->labels()
            ->withCount('workItems')
            ->orderBy('position')
            ->get()
            ->map(fn (ProjectItemLabel $l) => [
                'id' => $l->id,
                'name' => $l->name,
                'description' => $l->description,
                'color' => $l->color,
                'archived' => $l->isArchived(),
                // §24: how often it is used, so an admin can spot the unused ones.
                'usage' => $l->work_items_count,
            ])->all();
    }
}
