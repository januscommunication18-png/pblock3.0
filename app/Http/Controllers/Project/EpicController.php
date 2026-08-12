<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Http\Requests\Project\StoreEpicRequest;
use App\Http\Requests\Project\UpdateEpicRequest;
use App\Models\Epic;
use App\Models\EpicActivity;
use App\Models\Module;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkspaceMembership;
use App\Services\EpicActivityRecorder;
use App\Services\EpicProgress;
use App\Services\ProjectNavigation;
use App\Services\WorkItemBlockers;
use App\Services\WorkItemScreenPayload;
use App\Services\WorkItemStatusUpdates;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Project Workspace → Epics (Epic §3, §6-§9, §16).
 *
 * Reading survives the feature switch. Disabling Epics makes this whole screen read-only —
 * every write ability goes through EpicPolicy::create(), which is gated — but the page itself
 * still loads, because existing epics have to stay accessible for historical reference and
 * nothing about disable is a delete.
 *
 * Built like Modules and Cycles: one Vue root and one payload for both the list and a single
 * epic, with `pageEpicId` telling the client to render the detail instead of the landing page
 * — so an epic URL is real and linkable rather than a list with a panel over it.
 *
 * Every action starts from EpicPolicy, which refuses outright when Epics is switched off for
 * the project (§4). Cross-project ids 404 rather than 403, so a response never confirms that an
 * epic exists somewhere the user cannot see.
 */
class EpicController extends Controller
{
    public function __construct(
        private readonly EpicProgress $progress,
        private readonly EpicActivityRecorder $activity,
        private readonly ProjectNavigation $navigation,
        private readonly WorkItemBlockers $blockers,
        private readonly WorkItemStatusUpdates $statusUpdates,
        private readonly WorkItemScreenPayload $payload,
    ) {}

    /** GET /projects/{project}/epics */
    public function index(Project $project): View
    {
        abort_unless(Auth::user()->can('viewAny', [Epic::class, $project]), 404);

        return $this->screen($project);
    }

    /** GET /projects/{project}/epics/{epic} — the stable per-epic URL (§8). */
    public function show(Project $project, Epic $epic): View
    {
        $this->guardEpic($project, $epic, 'view');

        return $this->screen($project, $epic);
    }

    /** POST /projects/{project}/epics (§6). */
    public function store(StoreEpicRequest $request, Project $project): JsonResponse
    {
        // The ability is checked by StoreEpicRequest::authorize(), which runs first.
        $data = $request->validated();

        $epic = DB::transaction(function () use ($data, $project) {
            $epic = Epic::create(collect($data)->except('member_ids')->all() + [
                'project_id' => $project->id,
                // §6: the project comes from the URL, never from the payload, and the ID is
                // drawn inside the transaction so two simultaneous creates cannot collide.
                'identifier' => Epic::nextIdentifier($project->id),
                'created_by' => Auth::id(),
            ]);
            $epic->members()->sync($data['member_ids'] ?? []);
            $this->activity->created($epic, Auth::user());

            return $epic;
        });

        return response()->json([
            'ok' => true,
            'epic' => $this->card($epic->fresh(['lead', 'members', 'creator'])),
            'message' => 'Epic created.',
        ], 201);
    }

    /** PATCH /projects/{project}/epics/{epic} (§7). */
    public function update(UpdateEpicRequest $request, Project $project, Epic $epic): JsonResponse
    {
        // UpdateEpicRequest::authorize() has checked the ability; this is the ownership half —
        // an epic reached through the wrong project's URL is not found.
        abort_unless($epic->project_id === $project->id, 404);

        $data = $request->validated();

        DB::transaction(function () use ($epic, $data) {
            $before = $epic->only(array_keys(collect($data)->except('member_ids')->all()));
            $before['members'] = $epic->members()->pluck('users.id')->all();

            $epic->fill(collect($data)->except('member_ids')->all())->save();

            // §7: the form sends the whole list, so a removed member has to disappear.
            if (array_key_exists('member_ids', $data)) {
                $epic->members()->sync($data['member_ids']);
                $data['members'] = $data['member_ids'];
            }

            $this->activity->updated($epic, Auth::user(), $before, $data);
        });

        return response()->json([
            'ok' => true,
            'epic' => $this->card($epic->fresh(['lead', 'members', 'creator'])),
            'message' => 'Epic updated.',
        ]);
    }

    /** POST /projects/{project}/epics/{epic}/archive (§16). */
    public function archive(Project $project, Epic $epic): JsonResponse
    {
        $this->guardEpic($project, $epic, 'archive');

        // §16: work items keep their reference to an archived epic. Archiving only takes it
        // out of the active list and the work item selector.
        $epic->forceFill(['archived_at' => now()])->save();
        $this->activity->archived($epic, Auth::user());

        return response()->json([
            'ok' => true,
            'epic' => $this->card($epic->fresh(['lead', 'members', 'creator'])),
            'message' => 'Epic archived.',
        ]);
    }

    /** POST /projects/{project}/epics/{epic}/restore (§16). */
    public function restore(Project $project, Epic $epic): JsonResponse
    {
        $this->guardEpic($project, $epic, 'archive');
        $epic->forceFill(['archived_at' => null])->save();
        $this->activity->archived($epic, Auth::user(), restored: true);

        return response()->json([
            'ok' => true,
            'epic' => $this->card($epic->fresh(['lead', 'members', 'creator'])),
            'message' => 'Epic restored.',
        ]);
    }

    /**
     * DELETE /projects/{project}/epics/{epic} (§16).
     *
     * The de-linking is done HERE, explicitly, and that is not belt-and-braces over the FK's
     * nullOnDelete — it is the only thing that does it. Epic soft-deletes so its activity and
     * audit trail survive (§22), which means the row never leaves the table and the database
     * constraint never fires. A soft delete that left `epic_id` pointing at an invisible epic
     * would show work items filed under an epic nobody can open.
     *
     * Modules and cycles on those items are untouched — §16 is explicit, and the only way to
     * guarantee it is to not go near them.
     */
    public function destroy(Project $project, Epic $epic): JsonResponse
    {
        $this->guardEpic($project, $epic, 'delete');

        $count = DB::transaction(function () use ($epic) {
            $count = $epic->workItems()->count();
            $epic->workItems()->update(['epic_id' => null]);
            $epic->delete();

            return $count;
        });

        return response()->json([
            'ok' => true,
            'message' => $count === 0
                ? 'Epic deleted.'
                : ($count === 1 ? 'Epic deleted. 1 work item kept.' : "Epic deleted. {$count} work items kept."),
        ]);
    }

    /**
     * POST /projects/{project}/epics/{epic}/work-items — add existing items (§9).
     *
     * Assigning simply sets the column. §9 gives an item ONE epic, so adding it here moves it
     * out of whatever epic it was in — there is nothing to detach first.
     */
    public function addWorkItems(Request $request, Project $project, Epic $epic): JsonResponse
    {
        $this->guardEpic($project, $epic, 'manageWorkItems');

        $ids = $request->validate([
            'work_item_ids' => ['required', 'array', 'max:200'],
            'work_item_ids.*' => ['integer'],
        ])['work_item_ids'];

        // Only work items from THIS project, and only ones the user may actually touch.
        $items = WorkItem::query()
            ->forProject($project->id)
            ->whereIn('id', $ids)
            ->get()
            ->filter(fn (WorkItem $i) => Auth::user()->can('update', $i));

        DB::transaction(function () use ($items, $epic) {
            foreach ($items as $item) {
                if ((int) $item->epic_id === (int) $epic->id) {
                    continue; // already here — a no-op, not an error (§9)
                }

                $item->forceFill(['epic_id' => $epic->id])->save();
                $this->activity->workItemLinked($epic, Auth::user(), $item);
            }
        });

        return response()->json([
            'ok' => true,
            'epic' => $this->card($epic->fresh(['lead', 'members', 'creator'])),
            'items' => $this->workItems($epic),
            'message' => $items->count() === 1 ? '1 work item added.' : "{$items->count()} work items added.",
        ]);
    }

    /** DELETE /projects/{project}/epics/{epic}/work-items/{workItem} (§9/§16). */
    public function removeWorkItem(Project $project, Epic $epic, WorkItem $workItem): JsonResponse
    {
        $this->guardEpic($project, $epic, 'manageWorkItems');

        // §9: clearing the field removes the relationship without deleting anything. The
        // item's module and cycle are not touched (§11/§12).
        if ((int) $workItem->epic_id === (int) $epic->id) {
            $workItem->forceFill(['epic_id' => null])->save();
            $this->activity->workItemLinked($epic, Auth::user(), $workItem, removed: true);
        }

        return response()->json([
            'ok' => true,
            'items' => $this->workItems($epic),
            'message' => 'Work item removed from the epic.',
        ]);
    }

    /**
     * GET /projects/{project}/epics/{epic}/search?q= — candidates for "Add work items".
     *
     * Restricted to items the user may actually see, so the picker cannot become a way to read
     * titles that the project's Work Item View hides.
     */
    public function search(Request $request, Project $project, Epic $epic): JsonResponse
    {
        $this->guardEpic($project, $epic, 'manageWorkItems');

        $query = trim((string) $request->query('q', ''));
        $user = Auth::user();

        $items = WorkItem::query()
            ->forProject($project->id)
            ->active()
            ->when($query !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('title', 'like', "%{$query}%")
                ->orWhere('identifier', 'like', "%{$query}%")))
            ->with('state')
            ->orderBy('sequence_no')
            ->limit(50)
            ->get()
            ->filter(fn (WorkItem $i) => $user->can('view', $i))
            ->map(fn (WorkItem $i) => [
                'id' => $i->id,
                'identifier' => $i->identifier,
                'title' => $i->title,
                'state' => $i->state ? ['name' => $i->state->name, 'color' => $i->state->color] : null,
                // Already in this epic: shown as selected rather than offered again.
                'linked' => (int) $i->epic_id === (int) $epic->id,
            ])->values()->all();

        return response()->json(['ok' => true, 'items' => $items]);
    }

    /** The Epics screen, as a list or focused on one epic. */
    private function screen(Project $project, ?Epic $pageEpic = null): View
    {
        $epics = Epic::query()
            ->forProject($project->id)
            ->with(['lead', 'members', 'creator'])
            ->orderByDesc('created_at')
            ->get();

        $progress = $this->progress->forEpics($epics);

        return view('projects.epics', [
            'workspace' => Auth::user()->currentWorkspace,
            'user' => Auth::user(),
            'project' => $project,
            'tabs' => $this->navigation->tabs($project),
            'activeTab' => 'epics',
            'projects' => $this->navigation->sidebarProjects(Auth::user()),
            'canCreateProject' => Auth::user()->can('create', [WorkItem::class, $project]),
            'canManage' => Auth::user()->can('manage', $project),
            'bootstrap' => [
                'project' => [
                    'id' => $project->id,
                    'name' => $project->name,
                    'identifier' => $project->identifier,
                    'emoji' => $project->emoji,
                ],
                'epics' => $epics->map(fn (Epic $e) => $this->card($e, $progress[$e->id] ?? null))->all(),
                // Set only on the per-epic URL: render the detail, not the landing page.
                'pageEpicId' => $pageEpic?->id,
                'items' => $pageEpic ? $this->workItems($pageEpic) : [],
                // §8's Work Items tab mounts the work items SCREEN, so it needs that screen's
                // whole payload — narrowed to this epic's items, but with every picker option
                // and endpoint the drawer needs to edit any property of any row it is given.
                'workItems' => $pageEpic ? $this->epicScreenPayload($project, $pageEpic) : null,
                'distribution' => $pageEpic ? $this->distribution($pageEpic) : null,
                'activity' => $pageEpic ? $this->activityFeed($pageEpic) : [],
                'states' => $project->states()->orderBy('position')->get()
                    ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'color' => $s->color, 'group' => $s->group])->all(),
                'members' => $this->workspaceMembers($project),
                'statuses' => collect(config('projects.epic_statuses'))
                    ->map(fn (array $meta, string $key) => ['key' => $key] + $meta)->values()->all(),
                'priorities' => collect(config('projects.work_item_priorities'))
                    ->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])->values()->all(),
                'defaultStatus' => config('projects.epic_default_status'),
                // Disabling Epics is a configuration change, never a delete: the page still
                // loads so existing epics stay accessible for historical reference, and this
                // is what tells the screen to render itself read-only and say why.
                'featureEnabled' => $project->featureEnabled('epics'),
                'disabledNotice' => 'Epics are currently disabled for this project. Enable Epics from Project Settings to create or manage Epics.',
                'settingsUrl' => route('projects.settings', ['project' => $project->id, 'section' => 'epics']),
                'canCreate' => Auth::user()->can('create', [Epic::class, $project]),
                'canDelete' => Auth::user()->can('manage', $project),
                'titleMax' => (int) config('projects.epic_title_max'),
                'descriptionMax' => (int) config('projects.epic_description_max'),
                'introVideo' => config('projects.epic_intro_video'),
                'endpoints' => [
                    'list' => route('projects.epics', $project),
                    'store' => route('projects.epics.store', $project),
                    'epic' => route('projects.epics.show', ['project' => $project->id, 'epic' => '__ID__']),
                    'update' => route('projects.epics.update', ['project' => $project->id, 'epic' => '__ID__']),
                    'destroy' => route('projects.epics.destroy', ['project' => $project->id, 'epic' => '__ID__']),
                    'archive' => route('projects.epics.archive', ['project' => $project->id, 'epic' => '__ID__']),
                    'restore' => route('projects.epics.restore', ['project' => $project->id, 'epic' => '__ID__']),
                    'addItems' => route('projects.epics.items.store', ['project' => $project->id, 'epic' => '__ID__']),
                    'search' => route('projects.epics.search', ['project' => $project->id, 'epic' => '__ID__']),
                    'removeItem' => route('projects.epics.items.destroy', [
                        'project' => $project->id, 'epic' => '__ID__', 'workItem' => '__ITEM__',
                    ]),
                    'workItem' => route('projects.work-items.show', ['project' => $project->id, 'workItem' => '__ID__']),
                ],
            ],
        ]);
    }

    /**
     * The epic must belong to THIS project — never trust the URL alone — and the user must
     * hold the ability.
     */
    private function guardEpic(Project $project, Epic $epic, string $ability): void
    {
        abort_unless($epic->project_id === $project->id, 404);
        abort_unless(Auth::user()->can($ability, $epic), $ability === 'view' ? 404 : 403);
    }

    /** @return array<string, mixed> */
    private function card(Epic $epic, ?array $progress = null): array
    {
        $progress ??= $this->progress->forEpic($epic);
        $meta = $epic->statusMeta();

        return [
            'id' => $epic->id,
            'identifier' => $epic->identifier,
            'title' => $epic->title,
            'description' => $epic->description,
            'status' => $epic->status,
            'status_label' => $meta['label'],
            'status_color' => $meta['color'],
            'priority' => $epic->priority,
            'priority_label' => config("projects.work_item_priorities.{$epic->priority}"),
            'start_date' => $epic->start_date?->format('Y-m-d'),
            'target_date' => $epic->target_date?->format('Y-m-d'),
            'archived' => $epic->isArchived(),
            'progress' => $progress,
            'lead' => $epic->lead ? $this->person($epic->lead) : null,
            'members' => $epic->members->map(fn (User $u) => $this->person($u))->values()->all(),
            'created_by' => $epic->creator?->displayName(),
            'created_at' => $epic->created_at?->toIso8601String(),
            'updated_at' => $epic->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function person(User $user): array
    {
        return [
            'id' => $user->id, 'name' => $user->displayName(),
            'initial' => $user->initial(), 'avatar_url' => $user->avatar_url,
        ];
    }

    /**
     * §8's cycle and module distribution summaries.
     *
     * This is the whole point of keeping Epic independent of both (§11/§12): an epic's work
     * spreads across modules and cycles, and the way to understand that is to report the
     * spread — not to force a hierarchy that would have made it a single answer.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function distribution(Epic $epic): array
    {
        $items = $epic->workItems()->active()->with(['cycle', 'modules'])->get();

        $cycles = [];
        $modules = [];

        foreach ($items as $item) {
            $key = $item->cycle?->id ?? 0;
            $cycles[$key] ??= ['id' => $item->cycle?->id, 'name' => $item->cycle?->name ?? 'No cycle', 'count' => 0];
            $cycles[$key]['count']++;

            if ($item->modules->isEmpty()) {
                $modules[0] ??= ['id' => null, 'name' => 'No module', 'count' => 0];
                $modules[0]['count']++;

                continue;
            }

            // A work item can be in several modules (Module §9.3), so it contributes to each.
            // The counts therefore sum to more than the item count, which is correct for a
            // distribution across a many-to-many and is labelled as such in the UI.
            foreach ($item->modules as $module) {
                $modules[$module->id] ??= ['id' => $module->id, 'name' => $module->title, 'count' => 0];
                $modules[$module->id]['count']++;
            }
        }

        $sort = function (array $rows) {
            usort($rows, fn ($a, $b) => $b['count'] <=> $a['count']);

            return array_values($rows);
        };

        return ['cycles' => $sort($cycles), 'modules' => $sort($modules)];
    }

    /**
     * The Activity tab (§8/§22), newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    private function activityFeed(Epic $epic): array
    {
        return EpicActivity::query()
            ->where('epic_id', $epic->id)
            ->with('actor')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (EpicActivity $a) => [
                'id' => $a->id,
                'event' => $a->event,
                'field' => $a->field,
                'meta' => $a->meta,
                'actor' => $a->actor ? $this->person($a->actor) : null,
                'at' => $a->created_at?->toIso8601String(),
            ])->all();
    }

    /**
     * The work items screen's payload, narrowed to one epic.
     *
     * `seedEpicId` is what makes "Add work item" on that tab add work to THIS epic — §23's
     * "add existing Work Items to this Epic or create a new Work Item". Without it the button
     * would quietly create an item outside the epic the user is looking at.
     *
     * @return array<string, mixed>
     */
    private function epicScreenPayload(Project $project, Epic $epic): array
    {
        return $this->payload->build($project, null, $this->epicItems($epic)) + [
            'seed' => $project->featureEnabled('epics') && ! $epic->isArchived()
                ? ['epic_id' => $epic->id]
                : [],
            // Mounted inside the epic screen, which has its own Add button in the header.
            'embedded' => true,
        ];
    }

    /**
     * This epic's work items, as models — the payload builder shapes them.
     *
     * @return Collection<int, WorkItem>
     */
    private function epicItems(Epic $epic)
    {
        return $epic->workItems()
            ->active()
            ->with(['state', 'assignees', 'labels', 'parent:id,identifier,title', 'cycle', 'epic', 'estimateValue', 'modules', 'creator'])
            ->orderBy('sequence_no')
            ->get();
    }

    /**
     * The work items in an epic, for the Overview's filters and counts.
     *
     * Kept alongside the screen payload above because these rows carry the epic's own extras —
     * the cycle and module each item sits in, which §8's filters read.
     *
     * @return array<int, array<string, mixed>>
     */
    private function workItems(Epic $epic): array
    {
        $user = Auth::user();

        $items = $epic->workItems()
            ->active()
            ->with(['state', 'assignees', 'labels', 'cycle', 'modules'])
            ->orderBy('sequence_no')
            ->get()
            ->filter(fn (WorkItem $i) => $user->can('view', $i))
            ->values();

        $ids = $items->pluck('id')->all();
        $blocked = $this->blockers->counts($ids);
        $concerns = $this->statusUpdates->currentConcerns($ids);

        return $items->map(fn (WorkItem $i) => [
            'id' => $i->id,
            'identifier' => $i->identifier,
            'title' => $i->title,
            'priority' => $i->priority,
            'start_date' => $i->start_date?->format('Y-m-d'),
            'due_date' => $i->due_date?->format('Y-m-d'),
            'state' => $i->state ? [
                'id' => $i->state->id, 'name' => $i->state->name,
                'color' => $i->state->color, 'group' => $i->state->group,
            ] : null,
            'group' => $i->state?->group ?? 'backlog',
            'blocked_by_count' => $blocked[$i->id] ?? 0,
            'status_update' => $concerns[$i->id] ?? null,
            'assignees' => $i->assignees->map(fn (User $u) => $this->person($u))->values()->all(),
            'labels' => $i->labels->map(fn ($l) => ['id' => $l->id, 'name' => $l->name, 'color' => $l->color])->values()->all(),
            // §8's Work Items tab lists Module and Cycle as columns; they also drive the
            // tab's own filters, which is the cross-dimension view §8 is asking for.
            'cycle' => $i->cycle ? ['id' => $i->cycle->id, 'name' => $i->cycle->name] : null,
            'modules' => $i->modules->map(fn (Module $m) => ['id' => $m->id, 'title' => $m->title])->values()->all(),
        ])->all();
    }

    /**
     * Lead and member options. Active members of THIS workspace only, so the picker cannot
     * leak users from another tenant.
     *
     * @return array<int, array<string, mixed>>
     */
    private function workspaceMembers(Project $project): array
    {
        return WorkspaceMembership::query()
            ->where('workspace_id', $project->tenant_id)
            ->where('status', WorkspaceMembership::STATUS_ACTIVE)
            ->with('user')
            ->get()
            ->filter(fn (WorkspaceMembership $m) => $m->user !== null)
            ->map(fn (WorkspaceMembership $m) => $this->person($m->user))
            ->values()->all();
    }
}
