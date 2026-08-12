<?php

namespace App\Services;

use App\Models\Cycle;
use App\Models\Epic;
use App\Models\EstimateValue;
use App\Models\Module;
use App\Models\Project;
use App\Models\ProjectEstimation;
use App\Models\ProjectItemLabel;
use App\Models\ProjectItemState;
use App\Models\WorkItem;
use App\Models\WorkspaceMembership;
use App\Policies\WorkItemPolicy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Everything the Work Items screen needs, as one payload.
 *
 * Lifted out of WorkItemController because the screen is no longer only its own page: an
 * Epic\'s Work Items tab mounts the SAME Vue component, so a row there is editable, opens the
 * same drawer, and cannot drift from the project list. Two payload builders would have been
 * two definitions of what a work item row is.
 *
 * `$items` lets a host narrow the set — an epic passes its own work items — while everything
 * else (states, labels, members, the picker options, every endpoint) stays project-scoped,
 * because the drawer must be able to edit any property of any row it is given.
 */
class WorkItemScreenPayload
{
    public function __construct(
        private readonly ProjectItemStateProvisioner $states,
        private readonly WorkItemBlockers $blockers,
        private readonly WorkItemStatusUpdates $statusUpdates,
    ) {}

    /**
     * @param  Collection<int, WorkItem>|null  $items  a narrowed set, or null for the project\'s own list
     * @return array<string, mixed>
     */
    public function build(Project $project, ?WorkItem $pageItem = null, $items = null): array
    {
        $states = $this->states->for($project);
        $canCreate = Auth::user()->can('create', [WorkItem::class, $project]);

        return [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'identifier' => $project->identifier,
                'emoji' => $project->emoji,
            ],
            'items' => $items !== null ? $this->cards($items) : $this->items($project, $pageItem),
            'states' => $states->map(fn (ProjectItemState $s) => [
                'id' => $s->id, 'name' => $s->name, 'color' => $s->color, 'group' => $s->group,
            ])->values()->all(),
            // §3/§15: the picker appears only where the project has labels on.
            'labelsEnabled' => $project->featureEnabled('labels'),
            'labels' => $this->labels($project),
            'members' => $this->projectMembers($project),
            // Cycles §8.1: the property only appears when the project has the feature on.
            'cyclesEnabled' => $project->featureEnabled('cycles'),
            'cycles' => $this->cycles($project),
            // Modules §9.1: the property only appears when the project has it on.
            'modulesEnabled' => $project->featureEnabled('modules'),
            'modules' => $this->modules($project),
            // The Linked pages section only appears where the project has Pages on.
            'pagesEnabled' => $project->featureEnabled('pages'),
            'epicsEnabled' => $project->featureEnabled('epics'),
            'epics' => $this->epics($project),
            // Estimation §4/§26: the chip only appears where the project has it on AND a
            // system is configured — §5 requires one before the property means anything.
            'estimatesEnabled' => $project->featureEnabled('estimates') && $this->estimates($project) !== [],
            'estimates' => $this->estimates($project),
            'priorities' => collect(config('projects.work_item_priorities'))
                ->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values()->all(),
            'defaultStateId' => $this->states->defaultState($project)?->id,
            'canCreate' => $canCreate,
            'canEdit' => $canCreate, // §34: whoever may create may also edit and delete.
            'mediaMaxKb' => (int) config('projects.media.max_kb'),
            'timeTracking' => true,
            'currentUserId' => Auth::id(),
            // Set only on the per-item URL: render the detail as a page, not a drawer.
            'pageItemId' => $pageItem?->id,
            'endpoints' => $this->endpoints($project),
        ];
    }

    /**
     * Just the option lists a picker needs — states, members, labels, cycles, epics, modules,
     * estimates, priorities — without the rows.
     *
     * The Views grid edits the same properties through the same pickers (Views §11.1), so it
     * needs the same choices. Taking them from `build()` rather than assembling a second set
     * is what keeps the two screens offering identical options: a cycle that stops being
     * assignable disappears from both, because there is one method deciding.
     *
     * @return array<string, mixed>
     */
    public function pickerOptions(Project $project): array
    {
        return collect($this->build($project))->only([
            'states', 'labels', 'labelsEnabled', 'members', 'cycles', 'cyclesEnabled',
            'modules', 'modulesEnabled', 'epics', 'epicsEnabled', 'estimates',
            'estimatesEnabled', 'priorities', 'defaultStateId', 'currentUserId',
        ])->all();
    }

    /**
     * Rows for a set the caller already resolved.
     *
     * @param  Collection<int, WorkItem>  $items
     * @return array<int, array<string, mixed>>
     */
    public function cards($items): array
    {
        $user = Auth::user();
        $visible = $items->filter(fn (WorkItem $i) => $user->can('view', $i))->values();
        $ids = $visible->pluck('id')->all();
        $blocked = $this->blockers->counts($ids);
        $concerns = $this->statusUpdates->currentConcerns($ids);

        return $visible->map(fn (WorkItem $i) => $this->card($i, $blocked[$i->id] ?? 0, $concerns[$i->id] ?? null))->all();
    }

    /** @return array<string, string> */
    private function endpoints(Project $project): array
    {
        $withId = fn (string $name) => route($name, ['project' => $project->id, 'workItem' => '__ID__']);

        return [
            'store' => route('projects.work-items.store', $project),
            'list' => route('projects.work-items', $project),
            // Row chips + the ⋯ action menu. `__ID__` is swapped client-side.
            'item' => $withId('projects.work-items.show'),
            'activity' => $withId('projects.work-items.activity'),
            // Collaboration tabs (§5-§11): one read, one write endpoint per kind.
            'feed' => $withId('projects.work-items.feed'),
            'comments' => $withId('projects.work-items.comments.store'),
            'updates' => $withId('projects.work-items.updates.store'),
            'worklogs' => $withId('projects.work-items.worklogs.store'),
            'update' => $withId('projects.work-items.update'),
            'archive' => $withId('projects.work-items.archive'),
            'duplicate' => $withId('projects.work-items.duplicate'),
            'destroy' => $withId('projects.work-items.destroy'),
            // Structure sections: one read endpoint, one write endpoint per kind.
            'structure' => $withId('projects.work-items.structure'),
            'search' => $withId('projects.work-items.search'),
            'subtasks' => $withId('projects.work-items.subtasks.store'),
            'relations' => $withId('projects.work-items.relations.store'),
            'links' => $withId('projects.work-items.links.store'),
            // Opening a linked page. Project-scoped like the pages themselves.
            'page' => route('projects.pages.show', ['project' => $project->id, 'page' => '__ID__']),
            'pageSearch' => $withId('projects.work-items.pages.search'),
            'pages' => $withId('projects.work-items.pages.store'),
            'createLabel' => $withId('projects.work-items.labels.store'),
            'mediaUpload' => route('projects.work-items.media.store', $project),
            'mediaGallery' => route('projects.work-items.media.index', $project),
        ];
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
    public function items(Project $project, ?WorkItem $pageItem = null): array
    {
        $user = Auth::user();
        // §10: a project set to "assigned work items only" filters the LIST too — hiding rows
        // in the client while the API still returns them is not a restriction.
        $assignedOnly = ! app(WorkItemPolicy::class)->canSeeEveryItem($user, $project);

        $items = WorkItem::query()
            ->forProject($project->id)
            ->active()
            ->when($assignedOnly, fn ($q) => $q->whereHas('assignees', fn ($a) => $a->whereKey($user->id)))
            ->with(['state', 'assignees', 'labels', 'parent:id,identifier,title', 'cycle', 'epic', 'estimateValue', 'modules', 'creator'])
            ->orderBy('sequence_no')
            ->limit((int) config('projects.work_item_page_size'))
            ->get();

        if ($pageItem && ! $items->contains('id', $pageItem->id)) {
            $items->push($pageItem->loadMissing(['state', 'assignees', 'labels', 'parent:id,identifier,title', 'cycle', 'epic', 'estimateValue', 'modules', 'creator']));
        }

        $ids = $items->pluck('id')->all();
        $blocked = $this->blockers->counts($ids);
        // §8: an item whose latest update says At Risk / Off Track says so on the row.
        $concerns = $this->statusUpdates->currentConcerns($ids);

        return $items->map(fn (WorkItem $i) => $this->card($i, $blocked[$i->id] ?? 0, $concerns[$i->id] ?? null))->all();
    }

    /**
     * @param  int|null  $blockedBy  Open blocker count; resolved on demand when not supplied
     *                               by the caller's bulk lookup.
     * @param  array<string, mixed>|null  $concern  Latest At Risk / Off Track update, likewise.
     * @return array<string, mixed> The row payload the Tabulator grid renders.
     */
    public function card(WorkItem $item, ?int $blockedBy = null, ?array $concern = null): array
    {
        $blockedBy ??= $this->blockers->counts([$item->id])[$item->id] ?? 0;
        $concern ??= $this->statusUpdates->currentConcerns([$item->id])[$item->id] ?? null;

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
            // The chip shows the parent's ID and title, so both travel with the row. Resolving
            // them from the loaded list instead only worked while the parent happened to be on
            // screen — a parent in another project, or one filtered out, read as "None".
            'parent' => $item->parent
                ? ['id' => $item->parent->id, 'identifier' => $item->parent->identifier, 'title' => $item->parent->title]
                : null,
            // §8.1: the chip shows the cycle's name, so the name travels with the row.
            'cycle_id' => $item->cycle_id,
            'cycle' => $item->cycle ? ['id' => $item->cycle->id, 'name' => $item->cycle->name, 'status' => $item->cycle->status()] : null,
            // §9.3: MANY modules, unlike the single cycle.
            // Epic §9: the chip shows the epic's title, so it travels with the row. One epic,
            // unlike the list of modules below — and independent of it (§11).
            // §16: the chip shows the label, so it travels with the row. Present even when
            // the feature is off — §27 keeps the stored value readable.
            'estimate_value_id' => $item->estimate_value_id,
            'estimate' => $item->estimateValue
                ? ['id' => $item->estimateValue->id, 'label' => $item->estimateValue->label]
                : null,
            'epic_id' => $item->epic_id,
            'epic' => $item->epic ? ['id' => $item->epic->id, 'title' => $item->epic->title] : null,
            'modules' => $item->relationLoaded('modules')
                ? $item->modules->map(fn (Module $m) => ['id' => $m->id, 'title' => $m->title, 'status' => $m->status])->values()->all()
                : [],
            'assignees' => $item->assignees->map(fn ($u) => [
                'id' => $u->id, 'name' => $u->displayName(),
                'initial' => $u->initial(), 'avatar_url' => $u->avatar_url,
            ])->values()->all(),
            'labels' => $item->labels->map(fn (ProjectItemLabel $l) => [
                'id' => $l->id, 'name' => $l->name, 'color' => $l->color,
            ])->values()->all(),
            // Shown as a chip on the list row (§27): this item is waiting on something else.
            'blocked_by_count' => $blockedBy,
            // §8: the label the row shows, and the comment behind its tooltip. Null when the
            // latest update is On Track — or when there has never been one.
            'status_update' => $concern,
            // Detail view footer (§4.4): who opened this work item and when it last moved.
            'created_by' => $item->creator?->displayName(),
            'created_at' => $item->created_at?->toIso8601String(),
            'updated_at' => $item->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Cycles offered by the work item's Cycle picker (§8.2).
     *
     * Only cycles that can still take work: §8.3.5 keeps finished cycles out of new
     * assignment, and offering them would mean showing options the server refuses. An item
     * already sitting in a completed cycle still renders its chip — that comes from the row,
     * not from this list.
     *
     * @return array<int, array<string, mixed>>
     */
    private function cycles(Project $project): array
    {
        if (! $project->featureEnabled('cycles')) {
            return [];
        }

        return Cycle::query()
            ->forProject($project->id)
            ->assignable()
            ->orderBy('start_date')
            ->get()
            ->map(fn (Cycle $c) => [
                'id' => $c->id, 'name' => $c->name, 'status' => $c->status(),
                'start_date' => $c->start_date?->format('Y-m-d'), 'end_date' => $c->end_date?->format('Y-m-d'),
            ])->all();
    }

    /**
     * Modules offered by the work item's Module picker (§9.2).
     *
     * Active modules only: §12.2 keeps archived ones out of the default picker, and offering
     * an option the server refuses is worse than not offering it.
     *
     * @return array<int, array<string, mixed>>
     */
    private function modules(Project $project): array
    {
        if (! $project->featureEnabled('modules')) {
            return [];
        }

        return Module::query()
            ->forProject($project->id)
            ->active()
            ->orderBy('title')
            ->get()
            ->map(fn (Module $m) => [
                'id' => $m->id, 'title' => $m->title,
                'status' => $m->status, 'status_label' => $m->statusMeta()['label'],
            ])->all();
    }

    /**
     * Epics offered by the work item's Epic picker (§9).
     *
     * Active epics only: §16 keeps archived ones out of active selectors, and offering an
     * option the server refuses is worse than not offering it.
     *
     * @return array<int, array<string, mixed>>
     */
    private function epics(Project $project): array
    {
        if (! $project->featureEnabled('epics')) {
            return [];
        }

        return Epic::query()
            ->forProject($project->id)
            ->active()
            ->orderBy('title')
            ->get()
            ->map(fn (Epic $e) => [
                'id' => $e->id, 'title' => $e->title, 'identifier' => $e->identifier,
                'status' => $e->status, 'status_label' => $e->statusMeta()['label'],
            ])->all();
    }

    /**
     * The estimate values the work item picker may offer (§11/§21).
     *
     * Active values only: an archived one is history, not a choice, and offering an option the
     * server refuses is worse than not offering it.
     *
     * @return array<int, array<string, mixed>>
     */
    private function estimates(Project $project): array
    {
        if (! $project->featureEnabled('estimates')) {
            return [];
        }

        $estimation = ProjectEstimation::query()->where('project_id', $project->id)->first();

        if (! $estimation) {
            return [];
        }

        return $estimation->activeValues()->get()
            ->map(fn (EstimateValue $v) => [
                'id' => $v->id, 'label' => $v->label, 'sort_order' => $v->sort_order,
            ])->values()->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function labels(Project $project): array
    {
        // §3: nothing to pick from while the feature is off. Assignments already on work items
        // still travel with their rows, so the chips stay readable (§28.5) — this is only the
        // list of what may be CHOSEN.
        if (! $project->featureEnabled('labels')) {
            return [];
        }

        return ProjectItemLabel::query()
            ->where('project_id', $project->id)
            // §11: archived labels leave the picker and stay on the items using them.
            ->active()
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
