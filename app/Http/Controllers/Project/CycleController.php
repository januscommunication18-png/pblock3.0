<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Http\Requests\Project\StoreCycleRequest;
use App\Http\Requests\Project\UpdateCycleRequest;
use App\Models\Cycle;
use App\Models\Project;
use App\Models\ProjectItemState;
use App\Models\User;
use App\Models\WorkItem;
use App\Services\CycleMetrics;
use App\Services\ProjectItemStateProvisioner;
use App\Services\ProjectNavigation;
use App\Services\WorkItemBlockers;
use App\Services\WorkItemUpdater;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Project Workspace → Cycles (Cycles §4-§10).
 *
 * The screen works like Work Items: one Vue root and one payload for both the list and a
 * single cycle, with `pageCycleId` telling the client to render the detail instead of the
 * landing page — so a cycle URL is real and linkable rather than a list with a panel over it.
 *
 * Every action starts from CyclePolicy, which refuses outright when Cycles is switched off
 * for the project (§3.2.4). Cross-project ids 404 rather than 403, so a response never
 * confirms that a cycle exists somewhere the user cannot see.
 */
class CycleController extends Controller
{
    public function __construct(
        private readonly CycleMetrics $metrics,
        private readonly ProjectNavigation $navigation,
        private readonly WorkItemUpdater $updater,
        private readonly WorkItemBlockers $blockers,
        private readonly ProjectItemStateProvisioner $states,
    ) {}

    /** GET /projects/{project}/cycles */
    public function index(Project $project): View
    {
        abort_unless(Auth::user()->can('viewAny', [Cycle::class, $project]), 404);

        return $this->screen($project);
    }

    /** GET /projects/{project}/cycles/{cycle} — the stable per-cycle URL (§7). */
    public function show(Project $project, Cycle $cycle): View
    {
        $this->guardCycle($project, $cycle, 'view');

        return $this->screen($project, $cycle);
    }

    /** POST /projects/{project}/cycles (§6). */
    public function store(StoreCycleRequest $request, Project $project): JsonResponse
    {
        // The ability is checked by StoreCycleRequest::authorize(), which runs first.
        $cycle = Cycle::create($request->validated() + [
            'project_id' => $project->id,
            'created_by' => Auth::id(),
        ]);

        return response()->json([
            'ok' => true,
            'cycle' => $this->card($cycle->fresh('creator')),
            'message' => 'Cycle created.',
        ], 201);
    }

    /** PATCH /projects/{project}/cycles/{cycle} (§9.1/§9.2). */
    public function update(UpdateCycleRequest $request, Project $project, Cycle $cycle): JsonResponse
    {
        // UpdateCycleRequest::authorize() has already checked the ability; this is the
        // ownership half — a cycle reached through the wrong project's URL is not found.
        abort_unless($cycle->project_id === $project->id, 404);

        $cycle->fill($request->validated())->save();

        return response()->json([
            'ok' => true,
            'cycle' => $this->card($cycle->fresh('creator')),
            'message' => 'Cycle updated.',
        ]);
    }

    /**
     * DELETE /projects/{project}/cycles/{cycle} (§5.2).
     *
     * The work items survive and simply lose their cycle (§8.3.7) — the FK is nullOnDelete,
     * so that holds even though the deletion is the cycle's own.
     */
    public function destroy(Project $project, Cycle $cycle): JsonResponse
    {
        $this->guardCycle($project, $cycle, 'delete');
        $cycle->delete();

        return response()->json(['ok' => true, 'message' => 'Cycle deleted.']);
    }

    /**
     * POST /projects/{project}/cycles/{cycle}/work-items — add existing items (§7.3).
     *
     * Each item goes through WorkItemUpdater, so an item already in another cycle is MOVED
     * and the move lands in its history (§8.4). Items already in this cycle are skipped by
     * the updater's own diff, so re-adding one is a no-op rather than a duplicate event.
     */
    public function addWorkItems(Request $request, Project $project, Cycle $cycle): JsonResponse
    {
        $this->guardCycle($project, $cycle, 'manageWorkItems');
        abort_if($cycle->isCompleted(), 422, 'That cycle has already ended.');

        $ids = $request->validate([
            'work_item_ids' => ['required', 'array', 'max:200'],
            'work_item_ids.*' => ['integer'],
        ])['work_item_ids'];

        $moved = $this->assign($project, $ids, $cycle->id);

        return response()->json([
            'ok' => true,
            'cycle' => $this->card($cycle->fresh('creator')),
            'items' => $this->workItems($cycle),
            'message' => $moved === 1 ? '1 work item added.' : "{$moved} work items added.",
        ]);
    }

    /** DELETE /projects/{project}/cycles/{cycle}/work-items/{workItem} (§8.3.7). */
    public function removeWorkItem(Project $project, Cycle $cycle, WorkItem $workItem): JsonResponse
    {
        $this->guardCycle($project, $cycle, 'manageWorkItems');
        abort_unless($workItem->cycle_id === $cycle->id, 404);

        $this->updater->update($workItem, Auth::user(), ['cycle_id' => null]);

        return response()->json([
            'ok' => true,
            'items' => $this->workItems($cycle),
            'message' => 'Work item removed from the cycle.',
        ]);
    }

    /**
     * POST /projects/{project}/cycles/{cycle}/transfer — move unfinished work on (§10).
     *
     * A move, never a copy: each item leaves this cycle in the same update that adds it to
     * the destination, and nothing else about the work item is touched.
     */
    public function transfer(Request $request, Project $project, Cycle $cycle): JsonResponse
    {
        $this->guardCycle($project, $cycle, 'manageWorkItems');

        $data = $request->validate([
            'to_cycle_id' => ['required', 'integer'],
            'work_item_ids' => ['sometimes', 'array'],
            'work_item_ids.*' => ['integer'],
        ]);

        $destination = Cycle::query()->forProject($project->id)->find($data['to_cycle_id']);

        // §10: the destination has to be a real cycle of this project that can still take
        // work — Active or Upcoming — and it cannot be the cycle being emptied.
        abort_if($destination === null || $destination->id === $cycle->id, 422, 'Choose a different cycle to transfer to.');
        abort_if($destination->isCompleted(), 422, 'That cycle has already ended. Choose an active or upcoming cycle.');

        // Only work that is not finished moves (§10) — completed and cancelled items stay
        // where they are, since they are the record of what this cycle achieved.
        $ids = WorkItem::query()
            ->forProject($project->id)
            ->where('cycle_id', $cycle->id)
            ->incomplete()
            ->when(! empty($data['work_item_ids']), fn ($q) => $q->whereIn('id', $data['work_item_ids']))
            ->pluck('id')->all();

        $moved = $this->assign($project, $ids, $destination->id);

        return response()->json([
            'ok' => true,
            'items' => $this->workItems($cycle),
            'message' => $moved === 1
                ? "1 work item moved to {$destination->name}."
                : "{$moved} work items moved to {$destination->name}.",
        ]);
    }

    /**
     * GET /projects/{project}/cycles/{cycle}/search?q= — candidates for "Add work items".
     *
     * Restricted to items the user may actually see, so the picker cannot become a way to
     * read titles that §10's Work Item View hides.
     */
    public function search(Request $request, Project $project, Cycle $cycle): JsonResponse
    {
        $this->guardCycle($project, $cycle, 'manageWorkItems');

        $query = trim((string) $request->query('q', ''));
        $user = Auth::user();

        $items = WorkItem::query()
            ->forProject($project->id)
            ->active()
            // Grouped: an ungrouped OR would escape every filter above it, including the
            // project scope, and offer the whole workspace's work items.
            ->where(fn ($q) => $q->whereNull('cycle_id')->orWhere('cycle_id', '!=', $cycle->id))
            ->when($query !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('title', 'like', "%{$query}%")
                ->orWhere('identifier', 'like', "%{$query}%")))
            ->with(['state', 'cycle'])
            ->orderBy('sequence_no')
            ->limit(50)
            ->get()
            ->filter(fn (WorkItem $i) => $user->can('view', $i))
            ->map(fn (WorkItem $i) => [
                'id' => $i->id,
                'identifier' => $i->identifier,
                'title' => $i->title,
                'state' => $i->state ? ['name' => $i->state->name, 'color' => $i->state->color, 'group' => $i->state->group] : null,
                // Named so the picker can warn that adding this MOVES it (§7.3).
                'cycle' => $i->cycle ? ['id' => $i->cycle->id, 'name' => $i->cycle->name] : null,
            ])->values()->all();

        return response()->json(['ok' => true, 'items' => $items]);
    }

    /**
     * Put a set of work items into a cycle, one auditable update each.
     *
     * @param  array<int, int>  $ids
     * @return int how many actually moved
     */
    private function assign(Project $project, array $ids, ?int $cycleId): int
    {
        if ($ids === []) {
            return 0;
        }

        /** @var User $actor */
        $actor = Auth::user();

        $items = WorkItem::query()
            ->forProject($project->id)
            ->whereIn('id', $ids)
            ->where(fn ($q) => $q->whereNull('cycle_id')->orWhere('cycle_id', '!=', $cycleId))
            ->get()
            // Never move something the user is not allowed to touch, even in bulk.
            ->filter(fn (WorkItem $i) => $actor->can('update', $i));

        return DB::transaction(function () use ($items, $actor, $cycleId) {
            foreach ($items as $item) {
                $this->updater->update($item, $actor, ['cycle_id' => $cycleId]);
            }

            return $items->count();
        });
    }

    /**
     * The Cycles screen, as a list or focused on one cycle.
     */
    private function screen(Project $project, ?Cycle $pageCycle = null): View
    {
        $cycles = Cycle::query()->forProject($project->id)->with('creator')->orderBy('start_date')->get();
        $breakdowns = $this->metrics->forCycles($cycles);

        return view('projects.cycles', [
            'workspace' => Auth::user()->currentWorkspace,
            'user' => Auth::user(),
            'project' => $project,
            'tabs' => $this->navigation->tabs($project),
            'activeTab' => 'cycles',
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
                'cycles' => $cycles->map(fn (Cycle $c) => $this->card($c, $breakdowns[$c->id] ?? null))->all(),
                // Set only on the per-cycle URL: render the detail, not the landing page.
                'pageCycleId' => $pageCycle?->id,
                'items' => $pageCycle ? $this->workItems($pageCycle) : [],
                // The grid groups by state in the project's own state order, exactly like the
                // Work Items list — so the same work reads the same way on both screens.
                'states' => $this->states->for($project)->map(fn (ProjectItemState $s) => [
                    'id' => $s->id, 'name' => $s->name, 'color' => $s->color, 'group' => $s->group,
                ])->values()->all(),
                'canCreate' => Auth::user()->can('create', [Cycle::class, $project]),
                'canDelete' => Auth::user()->can('manage', $project),
                'parallel' => $project->featureEnabled('parallel_cycles'),
                'nameMax' => (int) config('projects.cycle_name_max'),
                'descriptionMax' => (int) config('projects.cycle_description_max'),
                'today' => now()->toDateString(),
                'endpoints' => [
                    'list' => route('projects.cycles', $project),
                    'store' => route('projects.cycles.store', $project),
                    // `__ID__` is swapped client-side, as everywhere else on the project screens.
                    'cycle' => route('projects.cycles.show', ['project' => $project->id, 'cycle' => '__ID__']),
                    'update' => route('projects.cycles.update', ['project' => $project->id, 'cycle' => '__ID__']),
                    'destroy' => route('projects.cycles.destroy', ['project' => $project->id, 'cycle' => '__ID__']),
                    'addItems' => route('projects.cycles.items.store', ['project' => $project->id, 'cycle' => '__ID__']),
                    'transfer' => route('projects.cycles.transfer', ['project' => $project->id, 'cycle' => '__ID__']),
                    'search' => route('projects.cycles.search', ['project' => $project->id, 'cycle' => '__ID__']),
                    'removeItem' => route('projects.cycles.items.destroy', [
                        'project' => $project->id, 'cycle' => '__ID__', 'workItem' => '__ITEM__',
                    ]),
                    'workItem' => route('projects.work-items.show', ['project' => $project->id, 'workItem' => '__ID__']),
                ],
            ],
        ]);
    }

    /**
     * §28's shape, for cycles: the cycle must belong to THIS project — never trust the URL
     * alone — and the user must hold the ability.
     */
    private function guardCycle(Project $project, Cycle $cycle, string $ability): void
    {
        abort_unless($cycle->project_id === $project->id, 404);
        abort_unless(Auth::user()->can($ability, $cycle), $ability === 'view' ? 404 : 403);
    }

    /** @return array<string, mixed> */
    private function card(Cycle $cycle, ?array $breakdown = null): array
    {
        $breakdown ??= $this->metrics->forCycle($cycle);

        return [
            'id' => $cycle->id,
            'name' => $cycle->name,
            'description' => $cycle->description,
            'start_date' => $cycle->start_date?->format('Y-m-d'),
            'end_date' => $cycle->end_date?->format('Y-m-d'),
            // Derived from the dates every time it is read (§9), never stored.
            'status' => $cycle->status(),
            'days_remaining' => $cycle->daysRemaining(),
            'breakdown' => $breakdown,
            'created_by' => $cycle->creator ? [
                'id' => $cycle->creator->id, 'name' => $cycle->creator->displayName(),
                'initial' => $cycle->creator->initial(), 'avatar_url' => $cycle->creator->avatar_url,
            ] : null,
            'created_at' => $cycle->created_at?->toIso8601String(),
        ];
    }

    /**
     * The work items planned into a cycle (§7.2), filtered to what the reader may see.
     *
     * @return array<int, array<string, mixed>>
     */
    private function workItems(Cycle $cycle): array
    {
        $user = Auth::user();

        $items = WorkItem::query()
            ->forProject($cycle->project_id)
            ->where('cycle_id', $cycle->id)
            ->active()
            ->with(['state', 'assignees', 'labels'])
            ->orderBy('sequence_no')
            ->get()
            ->filter(fn (WorkItem $i) => $user->can('view', $i))
            ->values();

        // The same Blocked marker the project's work item list shows, from the same service.
        $blocked = $this->blockers->counts($items->pluck('id')->all());

        // Shaped exactly like WorkItemController's row payload, because the grid that renders
        // it is the same grid (Cycles §7.2).
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
            'assignees' => $i->assignees->map(fn (User $u) => [
                'id' => $u->id, 'name' => $u->displayName(),
                'initial' => $u->initial(), 'avatar_url' => $u->avatar_url,
            ])->values()->all(),
            'labels' => $i->labels->map(fn ($l) => ['id' => $l->id, 'name' => $l->name, 'color' => $l->color])->values()->all(),
        ])->all();
    }
}
