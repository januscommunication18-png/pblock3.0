<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Http\Requests\Project\StoreWorkItemLinkRequest;
use App\Models\Project;
use App\Models\ProjectItemLabel;
use App\Models\ProjectPage;
use App\Models\WorkItem;
use App\Models\WorkItemLink;
use App\Models\WorkItemRelation;
use App\Policies\WorkItemPolicy;
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
        // Picking a parent is the one search where the item's own descendants are illegal
        // choices — putting an item under its own child closes a loop (§25). They are dropped
        // from the results rather than offered and then refused: a row you are allowed to
        // click and not allowed to keep is a worse answer than a row that is not there.
        $excluded = $request->query('for') === 'parent'
            ? $this->descendantIds($workItem)
            : [];

        $user = Auth::user();
        // §10: the picker cannot become a way to read titles the setting hides.
        $assignedOnly = ! app(WorkItemPolicy::class)->canSeeEveryItem($user, $project);

        $items = WorkItem::query()
            ->active()
            ->when(! $workspaceWide, fn ($q) => $q->forProject($project->id))
            ->when($assignedOnly, fn ($q) => $q->whereHas('assignees', fn ($a) => $a->whereKey($user->id)))
            ->whereKeyNot($workItem->id) // never offer the item itself (§25/§36)
            ->when($excluded !== [], fn ($q) => $q->whereKeyNot($excluded))
            // Workspace-wide search reaches outside this project, so it has to answer for the
            // projects it reaches into. Two filters, both on the owning project:
            //
            //   - ARCHIVED projects are out. Archiving a project takes it off every list; its
            //     work items should not keep surfacing here as if nothing happened. The
            //     project being worked in is exempt — otherwise archiving it would break its
            //     own sub-task and relation pickers.
            //   - INVISIBLE projects are out. §18 gives a plain member only the projects they
            //     belong to, and a search that ignores that hands them the titles of every
            //     private project in the workspace. Owners and admins keep the full reach they
            //     have everywhere else.
            //
            // whereHas also drops any orphan whose project row is gone — deleting a project
            // cascades its work items, so that should be unreachable, and this makes it so.
            ->whereHas('project', fn ($p) => $p
                ->visibleTo($user)
                ->where(fn ($w) => $w->active()->orWhere($p->qualifyColumn('id'), $project->id)))
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

    /**
     * Every work item below this one, however deep.
     *
     * Iterative and level-by-level: one query per depth rather than one per node, and a `$seen`
     * guard so data that is already circular cannot spin here forever.
     *
     * @return array<int, int>
     */
    private function descendantIds(WorkItem $item): array
    {
        $seen = [];
        $frontier = [$item->id];

        while ($frontier !== []) {
            $next = WorkItem::query()->whereIn('parent_id', $frontier)->pluck('id')->all();
            $frontier = array_values(array_diff($next, $seen));
            foreach ($frontier as $id) {
                $seen[$id] = $id;
            }
        }

        return array_values($seen);
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

    /**
     * POST /projects/{project}/work-items/{workItem}/labels — create a label without leaving
     * the picker, and put it straight on this work item.
     *
     * Gated on the WORK ITEM create ability rather than project-manage: labelling is part of
     * working on an item, and a contributor who has to ask an admin before they can tag
     * something will simply not tag it. Editing and deleting labels stay in Project Settings,
     * where they affect every item that carries them.
     */
    public function storeLabel(Request $request, Project $project, WorkItem $workItem): JsonResponse
    {
        $this->guard($project, $workItem, 'update');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        $name = trim($data['name']);

        // Re-picking an existing name applies it rather than creating a duplicate: the
        // picker's "create" row appears while typing, and a near-miss on an existing label
        // should not fork the project's vocabulary.
        $label = ProjectItemLabel::query()
            ->where('project_id', $project->id)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();

        if (! $label) {
            $label = ProjectItemLabel::create([
                'project_id' => $project->id,
                'name' => $name,
                // `nullable` means the key may be absent entirely, not merely null.
                'color' => ($data['color'] ?? null) ?: $this->nextLabelColor($project),
                'position' => (int) ProjectItemLabel::query()->where('project_id', $project->id)->max('position') + 1,
            ]);
        }

        // Creating the label is all this does. Putting it ON the item goes through the same
        // PATCH every other label change uses, so there is one path that applies labels and
        // one that refreshes the row.
        return response()->json([
            'ok' => true,
            'label' => ['id' => $label->id, 'name' => $label->name, 'color' => $label->color],
            'labels' => ProjectItemLabel::query()->where('project_id', $project->id)->orderBy('position')
                ->get()->map(fn (ProjectItemLabel $l) => ['id' => $l->id, 'name' => $l->name, 'color' => $l->color])->all(),
        ]);
    }

    /** A colour from the shared palette, stepped by how many labels the project already has. */
    private function nextLabelColor(Project $project): string
    {
        $palette = config('projects.label_colors', ['#6366F1', '#EC4899', '#F59E0B', '#10B981', '#3B82F6', '#EF4444', '#8B5CF6', '#14B8A6']);
        $count = ProjectItemLabel::query()->where('project_id', $project->id)->count();

        return $palette[$count % count($palette)];
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
     * GET /projects/{project}/work-items/{workItem}/pages/search?q= — pages to link.
     *
     * The project's own PUBLISHED pages, and only when Pages is switched on: a picker that
     * offered documentation the project cannot open would be offering nothing.
     */
    public function searchPages(Request $request, Project $project, WorkItem $workItem): JsonResponse
    {
        $this->guard($project, $workItem, 'view');

        if (! $project->featureEnabled('pages')) {
            return response()->json(['ok' => true, 'items' => []]);
        }

        $term = trim((string) $request->query('q', ''));
        $linked = $workItem->pages()->pluck('project_pages.id')->all();

        $items = ProjectPage::query()
            ->forProject($project->id)
            ->active()
            // Published only. A draft is still being written, and pointing a work item at one
            // links to something whose author has not said is ready to be read. Pages already
            // linked keep showing on the work item even if they go back to draft later — that
            // is history, and this is only what may be chosen now.
            ->published()
            ->when($term !== '', fn ($q) => $q->where('title', 'like', "%{$term}%"))
            ->with('editor')
            ->orderByDesc('updated_at')
            ->limit(30)
            ->get()
            ->map(fn (ProjectPage $p) => [
                'id' => $p->id,
                'title' => $p->title,
                'status' => $p->status,
                'updated_by' => $p->editor?->displayName(),
                'updated_at' => $p->updated_at?->toIso8601String(),
                // Already linked pages show as such rather than being offered again.
                'linked' => in_array($p->id, $linked, true),
            ])->values()->all();

        return response()->json(['ok' => true, 'items' => $items]);
    }

    /** POST /projects/{project}/work-items/{workItem}/pages — link one or more. */
    public function storePages(Request $request, Project $project, WorkItem $workItem): JsonResponse
    {
        $this->guard($project, $workItem, 'update');
        abort_unless($project->featureEnabled('pages'), 403, 'Pages are disabled for this project.');

        $ids = $request->validate([
            'page_ids' => ['required', 'array', 'max:50'],
            'page_ids.*' => ['integer'],
        ])['page_ids'];

        // Only pages from THIS project: pages are project-scoped, and a crafted payload must
        // not reach across into another project's documentation.
        $pages = ProjectPage::query()->forProject($project->id)->whereIn('id', $ids)->pluck('id');

        // syncWithoutDetaching, so linking one that is already there is a no-op rather than an
        // error the author has to make sense of.
        $workItem->pages()->syncWithoutDetaching(
            $pages->mapWithKeys(fn ($id) => [$id => ['created_by' => Auth::id()]])->all(),
        );

        return $this->payload($workItem, $pages->count() === 1 ? '1 page linked.' : "{$pages->count()} pages linked.");
    }

    /** DELETE /projects/{project}/work-items/{workItem}/pages/{page} — unlink, never delete. */
    public function destroyPage(Project $project, WorkItem $workItem, ProjectPage $page): JsonResponse
    {
        $this->guard($project, $workItem, 'update');

        // Only the link goes. The page is documentation in its own right.
        $workItem->pages()->detach($page->id);

        return $this->payload($workItem, 'Page unlinked.');
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
