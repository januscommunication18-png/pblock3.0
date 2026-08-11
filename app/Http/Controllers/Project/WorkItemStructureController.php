<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Http\Requests\Project\StoreWorkItemLinkRequest;
use App\Models\Project;
use App\Models\WorkItem;
use App\Models\WorkItemLink;
use App\Models\WorkItemRelation;
use App\Services\WorkItemLinkManager;
use App\Services\WorkItemRelationManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * A work item's structure: sub-tasks, dependencies, relations and links
 * (Collaboration spec §19–§41).
 *
 * Reads come back as ONE payload, because the detail panel draws all four sections together
 * and four round trips to fill one panel is three too many. Writes stay separate, one action
 * per endpoint, and each returns the whole refreshed structure so the panel never has to
 * reconcile a partial response with what it already had.
 *
 * Every method re-checks the item against the project and the user against the ability
 * (§55): a work item id in the URL proves nothing on its own.
 */
class WorkItemStructureController extends Controller
{
    public function __construct(
        private readonly WorkItemRelationManager $relations,
        private readonly WorkItemLinkManager $links,
    ) {}

    /** GET /projects/{project}/work-items/{workItem}/structure */
    public function show(Project $project, WorkItem $workItem): JsonResponse
    {
        $this->guard($project, $workItem, 'view');

        return $this->payload($workItem);
    }

    /**
     * GET /projects/{project}/work-items/search — the picker behind every "add" dialog.
     *
     * Defaults to this project and widens to the workspace on request (§57); cross-workspace
     * is impossible rather than merely disallowed, since WorkItem is tenant-scoped.
     */
    public function search(Request $request, Project $project, WorkItem $workItem): JsonResponse
    {
        $this->guard($project, $workItem, 'view');

        $term = trim((string) $request->query('q', ''));
        $workspaceWide = $request->boolean('all_projects');

        $items = WorkItem::query()
            ->active()
            ->when(! $workspaceWide, fn ($q) => $q->forProject($project->id))
            ->whereKeyNot($workItem->id) // never offer the item itself (§25/§36)
            ->when($term !== '', fn ($q) => $q->where(function ($w) use ($term) {
                $w->where('title', 'like', "%{$term}%")->orWhere('identifier', 'like', "%{$term}%");
            }))
            ->with(['state', 'project:id,name,identifier'])
            ->orderBy('sequence_no')
            ->limit(30)
            ->get()
            ->map(fn (WorkItem $i) => [
                'id' => $i->id,
                'identifier' => $i->identifier,
                'title' => $i->title,
                'priority' => $i->priority,
                'project' => $i->project?->name,
                'state' => $i->state ? [
                    'id' => $i->state->id, 'name' => $i->state->name,
                    'color' => $i->state->color, 'group' => $i->state->group,
                ] : null,
            ])->values()->all();

        return response()->json(['ok' => true, 'items' => $items]);
    }

    /** POST /projects/{project}/work-items/{workItem}/subtasks — attach existing items (§22). */
    public function storeSubtasks(Request $request, Project $project, WorkItem $workItem): JsonResponse
    {
        $this->guard($project, $workItem, 'update');
        $ids = $this->itemIds($request);

        $added = $this->relations->addSubtasks($workItem, Auth::user(), $ids);

        return $this->payload($workItem, $added.' '.str('sub-task')->plural($added).' added.');
    }

    /** DELETE /projects/{project}/work-items/{workItem}/subtasks/{child} (§26). */
    public function destroySubtask(Project $project, WorkItem $workItem, WorkItem $child): JsonResponse
    {
        $this->guard($project, $workItem, 'update');
        abort_unless(Auth::user()->can('update', $child), 403);

        $this->relations->removeSubtask($workItem, $child, Auth::user());

        return $this->payload($workItem, 'Sub-task removed.');
    }

    /** POST /projects/{project}/work-items/{workItem}/relations (§30/§34). */
    public function storeRelations(Request $request, Project $project, WorkItem $workItem): JsonResponse
    {
        $this->guard($project, $workItem, 'update');
        $ids = $this->itemIds($request);

        $added = $this->relations->addRelations(
            $workItem, Auth::user(), (string) $request->input('relation_type'), $ids,
        );

        return $this->payload($workItem, $added.' '.str('relation')->plural($added).' added.');
    }

    /** DELETE /projects/{project}/work-items/{workItem}/relations/{relation} (§32). */
    public function destroyRelation(Project $project, WorkItem $workItem, WorkItemRelation $relation): JsonResponse
    {
        $this->guard($project, $workItem, 'update');

        // The relation must actually touch this work item — otherwise a valid relation id
        // from elsewhere in the workspace could be deleted through this item's URL.
        abort_unless(
            (int) $relation->work_item_id === (int) $workItem->id
            || (int) $relation->related_work_item_id === (int) $workItem->id,
            404,
        );

        $this->relations->removeRelation($relation, Auth::user());

        return $this->payload($workItem, 'Relation removed.');
    }

    /** POST /projects/{project}/work-items/{workItem}/links (§38). */
    public function storeLink(StoreWorkItemLinkRequest $request, Project $project, WorkItem $workItem): JsonResponse
    {
        $this->guard($project, $workItem, 'update');

        $this->links->create($workItem, Auth::user(), $request->validated('url'), $request->validated('title'));

        return $this->payload($workItem, 'Link added.');
    }

    /** PATCH /projects/{project}/work-items/{workItem}/links/{link} (§41). */
    public function updateLink(StoreWorkItemLinkRequest $request, Project $project, WorkItem $workItem, WorkItemLink $link): JsonResponse
    {
        $this->guard($project, $workItem, 'update');
        abort_unless((int) $link->work_item_id === (int) $workItem->id, 404);

        $this->links->update($link, Auth::user(), $request->validated('url'), $request->validated('title'));

        return $this->payload($workItem, 'Link updated.');
    }

    /** DELETE /projects/{project}/work-items/{workItem}/links/{link} (§41). */
    public function destroyLink(Project $project, WorkItem $workItem, WorkItemLink $link): JsonResponse
    {
        $this->guard($project, $workItem, 'update');
        abort_unless((int) $link->work_item_id === (int) $workItem->id, 404);

        $this->links->delete($link, Auth::user());

        return $this->payload($workItem, 'Link removed.');
    }

    /**
     * @return array<int, int>
     */
    private function itemIds(Request $request): array
    {
        $validated = $request->validate([
            'work_item_ids' => ['required', 'array', 'min:1', 'max:50'],
            'work_item_ids.*' => ['integer'],
        ]);

        return $validated['work_item_ids'];
    }

    private function payload(WorkItem $item, ?string $message = null): JsonResponse
    {
        return response()->json(array_filter([
            'ok' => true,
            'structure' => $this->relations->structureFor($item->fresh()),
            'message' => $message,
        ], fn ($v) => $v !== null));
    }

    /**
     * §55: the item must belong to THIS project and the user must hold the ability. 404 on a
     * cross-project id so the response cannot confirm the work item exists elsewhere (§12).
     */
    private function guard(Project $project, WorkItem $item, string $ability): void
    {
        abort_unless($item->project_id === $project->id, 404);
        abort_unless(Auth::user()->can($ability, $item), $ability === 'view' ? 404 : 403);
    }
}
