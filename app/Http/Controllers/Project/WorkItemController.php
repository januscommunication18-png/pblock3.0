<?php

namespace App\Http\Controllers\Project;

use App\Filters\FilterRegistry;
use App\Filters\FilterSet;
use App\Http\Controllers\Controller;
use App\Http\Requests\Project\StoreWorkItemRequest;
use App\Http\Requests\Project\UpdateWorkItemRequest;
use App\Models\Cycle;
use App\Models\Project;
use App\Models\WorkItem;
use App\Models\WorkItemActivity;
use App\Services\ProjectItemStateProvisioner;
use App\Services\ProjectNavigation;
use App\Services\WorkItemBlockers;
use App\Services\WorkItemCreator;
use App\Services\WorkItemScreenPayload;
use App\Services\WorkItemStatusUpdates;
use App\Services\WorkItemUpdater;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Project Workspace → Work Items (Phase 5).
 *
 * Every project tab is a built screen with its own controller now — Overview was the last
 * placeholder, so the Coming Soon catch-all it shared with the others is gone.
 * Tenancy is initialized by the workspace.tenancy middleware, so
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
        private readonly WorkItemBlockers $blockers,
        private readonly WorkItemStatusUpdates $statusUpdates,
        private readonly WorkItemScreenPayload $payload,
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
    private function screen(Project $project, ?WorkItem $pageItem = null, string $view = 'projects.work-items'): View
    {
        $states = $this->states->for($project);
        $canCreate = Auth::user()->can('create', [WorkItem::class, $project]);

        return view($view, [
            'workspace' => Auth::user()->currentWorkspace,
            'user' => Auth::user(),
            'project' => $project,
            // Null in list mode. The chrome-less frame uses it for the document title, which
            // is the tooltip a browser shows for an embedded page.
            'workItem' => $pageItem,
            'tabs' => $this->navigation->tabs($project),
            'activeTab' => 'work-items',
            // Shared sidebar: project list + the gate on its "New work item" action.
            'projects' => $this->navigation->sidebarProjects(Auth::user()),
            'canCreateProject' => $canCreate,
            // Gates Settings in the header's ⋯ menu (the settings screen re-checks it).
            'canManage' => Auth::user()->can('manage', $project),
            // The address is the filter state, so the request is where they come from
            // (docs/features/filters.md, F-D2). A stale or hand-edited link narrows differently
            // rather than erroring: unknown values are dropped, and the chips show what ran.
            'bootstrap' => $this->payload->build($project, $pageItem, null, FilterSet::fromRequest(
                request(),
                app(FilterRegistry::class)->workItems($project),
                $project,
            )),
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

        // §12: an item created with no assignee chosen falls to the project's default; a
        // manual choice overrides it. The create form always sends the key, so "none chosen"
        // is an empty list rather than a missing one.
        if (empty($data['assignee_ids']) && $project->default_assignee_id) {
            $data['assignee_ids'] = [$project->default_assignee_id];
        }

        $item = $this->creator->create(Auth::user(), $project, $data);

        return response()->json([
            'ok' => true,
            'item' => $this->payload->card($item->fresh(['state', 'assignees', 'labels', 'parent:id,identifier,title', 'cycle', 'epic', 'estimateValue', 'modules', 'creator'])),
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

    /**
     * GET /projects/{project}/work-items/{workItem}/frame — the same detail, without the app
     * chrome, for embedding in a slide-over panel (Views §7.3).
     *
     * The Views grid opens a work item in a panel beside the grid rather than navigating away
     * from it. The panel embeds THIS, so the detail is the real drawer with every picker, tab,
     * editor and relation working — not a second, thinner copy that would drift from it.
     *
     * The guard is identical to `show()`, deliberately re-run rather than inherited from
     * whatever screen linked here: an embeddable URL is still a URL, and it must answer for
     * itself.
     */
    public function frame(Project $project, WorkItem $workItem): View
    {
        $this->guardItem($project, $workItem, 'view');

        return $this->screen($project, $workItem, 'projects.work-item-frame');
    }

    /** PATCH /projects/{project}/work-items/{workItem} — inline row edits (§4.2). */
    public function update(UpdateWorkItemRequest $request, Project $project, WorkItem $workItem): JsonResponse
    {
        $this->guardItem($project, $workItem, 'update');

        $item = $this->updater->update($workItem, Auth::user(), $request->validated());

        return response()->json(['ok' => true, 'item' => $this->payload->card($item)]);
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

        return response()->json(['ok' => true, 'item' => $this->payload->card($item), 'message' => 'Work item restored.']);
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
            // Cycle deliberately absent: a copy starts unplanned. Carrying it over would drop
            // work into a sprint's scope without anyone deciding to, and would put items into
            // a finished cycle that §8.3.5 refuses through every other route.
            'assignee_ids' => $workItem->assignees->pluck('id')->all(),
            'label_ids' => $workItem->labels->pluck('id')->all(),
        ]);

        return response()->json([
            'ok' => true,
            'item' => $this->payload->card($copy->fresh(['state', 'assignees', 'labels', 'parent:id,identifier,title', 'cycle', 'epic', 'estimateValue', 'modules', 'creator'])),
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
}
