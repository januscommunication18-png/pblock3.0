<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Http\Requests\Project\StoreWorkItemRequest;
use App\Http\Requests\Project\UpdateWorkItemRequest;
use App\Models\Project;
use App\Models\ProjectItemLabel;
use App\Models\ProjectItemState;
use App\Models\WorkItem;
use App\Models\WorkItemActivity;
use App\Models\WorkItemRelation;
use App\Models\WorkspaceMembership;
use App\Services\ProjectItemStateProvisioner;
use App\Services\ProjectNavigation;
use App\Services\WorkItemCreator;
use App\Services\WorkItemUpdater;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Project Workspace → Work Items (Phase 5).
 *
 * The only functional project tab this phase; the other five render a Coming Soon page from
 * ProjectWorkspaceController. Tenancy is initialized by the workspace.tenancy middleware, so
 * Project/WorkItem bindings and queries are auto-confined to the workspace; project scoping
 * is then applied explicitly on top (requirements §7).
 */
class WorkItemController extends Controller
{
    public function __construct(
        private readonly ProjectItemStateProvisioner $states,
        private readonly WorkItemCreator $creator,
        private readonly WorkItemUpdater $updater,
        private readonly ProjectNavigation $navigation,
    ) {}

    /** GET /projects/{project}/work-items */
    public function index(Project $project): View
    {
        // 404 rather than 403: never reveal that an inaccessible project exists (spec §12).
        abort_unless(Auth::user()->can('viewAny', [WorkItem::class, $project]), 404);

        return $this->screen($project);
    }

    /**
     * The Work Items screen, in list mode or focused on one item.
     *
     * Both modes render the same payload and the same Vue root: the detail view is the drawer
     * either way, and `pageItemId` only tells the client to render it as a full page with the
     * list hidden (§4.4 — "open in new tab" must land on a real, linkable page, not a list
     * with a panel floating over it).
     */
    private function screen(Project $project, ?WorkItem $pageItem = null): View
    {
        $states = $this->states->for($project);
        $canCreate = Auth::user()->can('create', [WorkItem::class, $project]);

        return view('projects.work-items', [
            'workspace' => Auth::user()->currentWorkspace,
            'user' => Auth::user(),
            'project' => $project,
            'tabs' => config('projects.workspace_tabs'),
            'activeTab' => 'work-items',
            // Shared sidebar: project list + the gate on its "New work item" action.
            'projects' => $this->navigation->sidebarProjects(Auth::user()),
            'canCreateProject' => $canCreate,
            // Gates Settings in the header's ⋯ menu (the settings screen re-checks it).
            'canManage' => Auth::user()->can('manage', $project),
            'bootstrap' => [
                'project' => [
                    'id' => $project->id,
                    'name' => $project->name,
                    'identifier' => $project->identifier,
                    'emoji' => $project->emoji,
                ],
                'items' => $this->items($project, $pageItem),
                'states' => $states->map(fn (ProjectItemState $s) => [
                    'id' => $s->id, 'name' => $s->name, 'color' => $s->color, 'group' => $s->group,
                ])->values()->all(),
                'labels' => $this->labels($project),
                'members' => $this->projectMembers($project),
                'priorities' => collect(config('projects.work_item_priorities'))
                    ->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values()->all(),
                'defaultStateId' => $this->states->defaultState($project)?->id,
                'canCreate' => $canCreate,
                'canEdit' => $canCreate, // §34: whoever may create may also edit and delete.
                // Description editor media limit — the client refuses oversized files before
                // uploading them; the server re-checks (§80).
                'mediaMaxKb' => (int) config('projects.media.max_kb'),
                // §9.2: the Worklogs tab is meant to follow a project Time Tracking toggle.
                // No such setting exists yet — `projects.features` is unbuilt — so it is on
                // for every project, and this is the one line that changes when it ships.
                'timeTracking' => true,
                'currentUserId' => Auth::id(),
                // Set only on the per-item URL: render the detail as a page, not a drawer.
                'pageItemId' => $pageItem?->id,
                'endpoints' => [
                    'store' => route('projects.work-items.store', $project),
                    'list' => route('projects.work-items', $project),
                    // Row chips + the ⋯ action menu. `__ID__` is swapped client-side.
                    'item' => route('projects.work-items.show', ['project' => $project->id, 'workItem' => '__ID__']),
                    'activity' => route('projects.work-items.activity', ['project' => $project->id, 'workItem' => '__ID__']),
                    // Collaboration tabs (§5-§11): one read, one write endpoint per kind.
                    'feed' => route('projects.work-items.feed', ['project' => $project->id, 'workItem' => '__ID__']),
                    'comments' => route('projects.work-items.comments.store', ['project' => $project->id, 'workItem' => '__ID__']),
                    'updates' => route('projects.work-items.updates.store', ['project' => $project->id, 'workItem' => '__ID__']),
                    'worklogs' => route('projects.work-items.worklogs.store', ['project' => $project->id, 'workItem' => '__ID__']),
                    'update' => route('projects.work-items.update', ['project' => $project->id, 'workItem' => '__ID__']),
                    'archive' => route('projects.work-items.archive', ['project' => $project->id, 'workItem' => '__ID__']),
                    'duplicate' => route('projects.work-items.duplicate', ['project' => $project->id, 'workItem' => '__ID__']),
                    'destroy' => route('projects.work-items.destroy', ['project' => $project->id, 'workItem' => '__ID__']),
                    // The editor's image upload target and its gallery source.
                    // Structure sections: one read endpoint, one write endpoint per kind.
                    'structure' => route('projects.work-items.structure', ['project' => $project->id, 'workItem' => '__ID__']),
                    'search' => route('projects.work-items.search', ['project' => $project->id, 'workItem' => '__ID__']),
                    'subtasks' => route('projects.work-items.subtasks.store', ['project' => $project->id, 'workItem' => '__ID__']),
                    'relations' => route('projects.work-items.relations.store', ['project' => $project->id, 'workItem' => '__ID__']),
                    'links' => route('projects.work-items.links.store', ['project' => $project->id, 'workItem' => '__ID__']),
                    'mediaUpload' => route('projects.work-items.media.store', $project),
                    'mediaGallery' => route('projects.work-items.media.index', $project),
                ],
            ],
        ]);
    }

    /** POST /projects/{project}/work-items */
    public function store(StoreWorkItemRequest $request, Project $project): JsonResponse
    {
        abort_unless(Auth::user()->can('create', [WorkItem::class, $project]), 403);

        // Make sure the project has states before defaulting to one (projects created before
        // Phase 5 have none until Work Items is opened).
        $this->states->for($project);

        $data = $request->validated();
        $data['state_id'] ??= $this->states->defaultState($project)?->id;

        $item = $this->creator->create(Auth::user(), $project, $data);

        return response()->json([
            'ok' => true,
            'item' => $this->card($item->fresh(['state', 'assignees', 'labels', 'creator'])),
        ], 201);
    }

    /**
     * GET /projects/{project}/work-items/{workItem} — the stable per-item URL (§4.4).
     *
     * "Open in new tab" and "Copy link" resolve here, and it renders the item's detail as a
     * full page: same content as the drawer, with the list and its toolbar out of the way.
     */
    public function show(Project $project, WorkItem $workItem): View
    {
        $this->guardItem($project, $workItem, 'view');

        return $this->screen($project, $workItem);
    }

    /** PATCH /projects/{project}/work-items/{workItem} — inline row edits (§4.2). */
    public function update(UpdateWorkItemRequest $request, Project $project, WorkItem $workItem): JsonResponse
    {
        $this->guardItem($project, $workItem, 'update');

        $item = $this->updater->update($workItem, Auth::user(), $request->validated());

        return response()->json(['ok' => true, 'item' => $this->card($item)]);
    }

    /** POST /projects/{project}/work-items/{workItem}/archive (§4.4). */
    public function archive(Project $project, WorkItem $workItem): JsonResponse
    {
        $this->guardItem($project, $workItem, 'update');
        $this->updater->setArchived($workItem, Auth::user(), true);

        return response()->json(['ok' => true, 'message' => 'Work item archived.']);
    }

    /** POST /projects/{project}/work-items/{workItem}/restore (§4.4). */
    public function restore(Project $project, WorkItem $workItem): JsonResponse
    {
        $this->guardItem($project, $workItem, 'update');
        $item = $this->updater->setArchived($workItem, Auth::user(), false);

        return response()->json(['ok' => true, 'item' => $this->card($item), 'message' => 'Work item restored.']);
    }

    /**
     * POST /projects/{project}/work-items/{workItem}/duplicate — "Make a copy" (§4.4).
     * Same project only; §10 defers cross-project copies.
     */
    public function duplicate(Project $project, WorkItem $workItem): JsonResponse
    {
        $this->guardItem($project, $workItem, 'update');
        abort_unless(Auth::user()->can('create', [WorkItem::class, $project]), 403);

        $workItem->loadMissing(['assignees', 'labels']);

        // A copy is a new work item: it gets its own sequential ID and its own creation entry.
        $copy = $this->creator->create(Auth::user(), $project, [
            'title' => $workItem->title,
            'description' => $workItem->description,
            'state_id' => $workItem->state_id,
            'priority' => $workItem->priority,
            'start_date' => $workItem->start_date?->format('Y-m-d'),
            'due_date' => $workItem->due_date?->format('Y-m-d'),
            'parent_id' => $workItem->parent_id,
            'assignee_ids' => $workItem->assignees->pluck('id')->all(),
            'label_ids' => $workItem->labels->pluck('id')->all(),
        ]);

        return response()->json([
            'ok' => true,
            'item' => $this->card($copy->fresh(['state', 'assignees', 'labels', 'creator'])),
            'message' => 'Work item copied.',
        ], 201);
    }

    /** DELETE /projects/{project}/work-items/{workItem} — permanent (§4.4). */
    public function destroy(Project $project, WorkItem $workItem): JsonResponse
    {
        $this->guardItem($project, $workItem, 'delete');
        $workItem->delete();

        return response()->json(['ok' => true, 'message' => 'Work item deleted.']);
    }

    /**
     * §28: the item must belong to THIS project — never trust the URL alone — and the user
     * must hold the ability. 404 rather than 403 on a cross-project id, so the response
     * cannot confirm that a work item exists elsewhere.
     */
    private function guardItem(Project $project, WorkItem $item, string $ability): void
    {
        abort_unless($item->project_id === $project->id, 404);
        abort_unless(Auth::user()->can($ability, $item), $ability === 'view' ? 404 : 403);
    }

    /**
     * GET /projects/{project}/work-items/{workItem}/activity — the item's audit feed (§6).
     *
     * Oldest first, so it reads as a story from creation onward. Whoever may open the work
     * item may read its history; the feed exposes nothing the item itself does not.
     */
    public function activity(Project $project, WorkItem $workItem): JsonResponse
    {
        abort_unless($workItem->project_id === $project->id, 404);
        abort_unless(Auth::user()->can('view', $workItem), 404);

        $entries = WorkItemActivity::query()
            ->where('work_item_id', $workItem->id)
            ->with('actor')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (WorkItemActivity $a) => [
                'id' => $a->id,
                'event' => $a->event,
                'field' => $a->field,
                'old_value' => $a->old_value,
                'new_value' => $a->new_value,
                'meta' => $a->meta,
                'actor' => $a->actor
                    ? [
                        'id' => $a->actor->id, 'name' => $a->actor->displayName(),
                        'initial' => $a->actor->initial(), 'avatar_url' => $a->actor->avatar_url,
                    ]
                    : null,
                'created_at' => $a->created_at?->toIso8601String(),
            ])->all();

        return response()->json(['ok' => true, 'activity' => $entries]);
    }

    /**
     * The project's live work items, newest state-order first.
     *
     * `$pageItem` is appended when it is not already in that set — the detail page must be
     * able to render an archived item, or one past the list's page size, since its URL stays
     * valid either way (§4.4).
     *
     * @return array<int, array<string, mixed>>
     */
    private function items(Project $project, ?WorkItem $pageItem = null): array
    {
        $items = WorkItem::query()
            ->forProject($project->id)
            ->active()
            ->with(['state', 'assignees', 'labels', 'creator'])
            ->orderBy('sequence_no')
            ->limit((int) config('projects.work_item_page_size'))
            ->get();

        if ($pageItem && ! $items->contains('id', $pageItem->id)) {
            $items->push($pageItem->loadMissing(['state', 'assignees', 'labels', 'creator']));
        }

        $blocked = $this->blockedCounts($items->pluck('id')->all());

        return $items->map(fn (WorkItem $i) => $this->card($i, $blocked[$i->id] ?? 0))->all();
    }

    /**
     * How many UNRESOLVED blockers each of these work items has (§27–§29).
     *
     * A blocker that is itself done or cancelled is not holding anything up, so it does not
     * count — otherwise an item stays flagged blocked forever after the thing blocking it
     * shipped. One grouped query for the whole list rather than one per row.
     *
     * @param  array<int, int>  $itemIds
     * @return array<int, int> work item id => open blocker count
     */
    private function blockedCounts(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        return WorkItemRelation::query()
            ->where('relation_type', WorkItemRelation::TYPE_BLOCKING)
            ->whereIn('related_work_item_id', $itemIds)
            ->whereHas('workItem', fn ($q) => $q->whereDoesntHave(
                'state', fn ($s) => $s->whereIn('group', ['completed', 'cancelled']),
            ))
            ->selectRaw('related_work_item_id, COUNT(*) as blockers')
            ->groupBy('related_work_item_id')
            ->pluck('blockers', 'related_work_item_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /**
     * @param  int|null  $blockedBy  Open blocker count; resolved on demand when not supplied
     *                               by the caller's bulk lookup.
     * @return array<string, mixed> The row payload the Tabulator grid renders.
     */
    private function card(WorkItem $item, ?int $blockedBy = null): array
    {
        $blockedBy ??= $this->blockedCounts([$item->id])[$item->id] ?? 0;

        $state = $item->state;

        return [
            'id' => $item->id,
            'identifier' => $item->identifier,
            'title' => $item->title,
            'description' => $item->description,
            'state_id' => $item->state_id,
            'state' => $state ? ['id' => $state->id, 'name' => $state->name, 'color' => $state->color, 'group' => $state->group] : null,
            // The list groups by the state's stable group key, not its editable name.
            'group' => $state?->group ?? 'backlog',
            'priority' => $item->priority,
            'start_date' => $item->start_date?->format('Y-m-d'),
            'due_date' => $item->due_date?->format('Y-m-d'),
            'parent_id' => $item->parent_id,
            'assignees' => $item->assignees->map(fn ($u) => [
                'id' => $u->id, 'name' => $u->displayName(),
                'initial' => $u->initial(), 'avatar_url' => $u->avatar_url,
            ])->values()->all(),
            'labels' => $item->labels->map(fn (ProjectItemLabel $l) => [
                'id' => $l->id, 'name' => $l->name, 'color' => $l->color,
            ])->values()->all(),
            // Shown as a chip on the list row (§27): this item is waiting on something else.
            'blocked_by_count' => $blockedBy,
            // Detail view footer (§4.4): who opened this work item and when it last moved.
            'created_by' => $item->creator?->displayName(),
            'created_at' => $item->created_at?->toIso8601String(),
            'updated_at' => $item->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function labels(Project $project): array
    {
        return ProjectItemLabel::query()
            ->where('project_id', $project->id)
            ->orderBy('position')
            ->get()
            ->map(fn (ProjectItemLabel $l) => ['id' => $l->id, 'name' => $l->name, 'color' => $l->color])
            ->all();
    }

    /**
     * Assignee options. Restricted to active members of this workspace (spec §4.3), so the
     * picker cannot leak users from another workspace.
     *
     * @return array<int, array<string, mixed>>
     */
    private function projectMembers(Project $project): array
    {
        return WorkspaceMembership::query()
            ->where('workspace_id', $project->tenant_id)
            ->where('status', WorkspaceMembership::STATUS_ACTIVE)
            ->with('user')
            ->get()
            ->map(fn (WorkspaceMembership $m) => [
                'id' => $m->user_id,
                'name' => $m->user?->displayName(),
                'email' => $m->user?->email,
                'initial' => $m->user?->initial(),
                'avatar_url' => $m->user?->avatar_url,
            ])->all();
    }
}
