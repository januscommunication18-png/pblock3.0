<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\WorkItem;
use App\Models\WorkItemActivity;
use App\Services\ProjectNavigation;
use App\Services\WorkItemScreenPayload;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Your Work — one person's work items across every project they can see.
 *
 * Five tabs (docs/features/your-work.md). Three of them are the SAME list the project's Work
 * Items screen renders, differing only in which items they select and in one extra chip naming
 * the project a row came from — which is the only thing a cross-project row needs that a
 * project-scoped one does not.
 *
 * Everything here is bounded by ProjectNavigation::visible(). A work item cannot appear on
 * this screen because it was assigned to you if the project it lives in is one you were never
 * added to — being named on a row is not access to it (requirements §7). WorkItemPolicy@view
 * is then applied per row by WorkItemScreenPayload::cards(), which also enforces §10's
 * "assigned work items only" projects.
 */
class YourWorkController extends Controller
{
    /** In tab-bar order. `summary` leads because it is the landing tab. */
    private const TABS = ['summary', 'assigned', 'created', 'subscribed', 'activity'];

    public function __construct(
        private readonly ProjectNavigation $navigation,
        private readonly WorkItemScreenPayload $payload,
    ) {}

    /** GET /your-work/{tab?} */
    public function show(string $tab = 'summary'): View
    {
        abort_unless(in_array($tab, self::TABS, true), 404);

        $user = Auth::user();
        $projects = $this->navigation->visible($user)->get();

        return view('your-work.index', [
            'user' => $user,
            'workspace' => $user->currentWorkspace,
            'projects' => $this->navigation->sidebarProjects($user),
            'canCreateProject' => $user->can('create', [Project::class, $user->currentWorkspace]),
            'tab' => $tab,
            'bootstrap' => [
                'tab' => $tab,
                'tabs' => $this->tabs(),
                // The list payload for THIS tab only. The other tabs' rows are a navigation
                // away and would be stale by the time they were looked at — five lists in one
                // response is four lists nobody asked for.
                'workItems' => $this->isListTab($tab) ? $this->listPayload($tab, $user, $projects) : null,
                'activity' => $tab === 'activity' ? $this->activity($user, $projects) : [],
                'counts' => $this->counts($user, $projects),
            ],
        ]);
    }

    /** @return array<int, array<string, string>> */
    private function tabs(): array
    {
        return collect(self::TABS)->map(fn (string $key) => [
            'key' => $key,
            'label' => ucfirst($key),
            'url' => route('your-work', ['tab' => $key]),
        ])->all();
    }

    private function isListTab(string $tab): bool
    {
        return in_array($tab, ['assigned', 'created', 'subscribed'], true);
    }

    /**
     * The work items screen's own payload, for a set spanning projects.
     *
     * @param  Collection<int, Project>  $projects
     * @return array<string, mixed>
     */
    private function listPayload(string $tab, $user, Collection $projects): array
    {
        $items = $this->scope($tab, $user, $projects)
            ->with([
                'project', 'state', 'assignees', 'labels', 'parent:id,identifier,title',
                'cycle', 'epic', 'estimateValue', 'modules', 'creator',
            ])
            ->orderByDesc('updated_at')
            ->limit((int) config('projects.work_item_page_size'))
            ->get();

        return $this->payload->forUser($items, $projects);
    }

    /**
     * The query behind one list tab.
     *
     * @param  Collection<int, Project>  $projects
     * @return Builder<WorkItem>
     */
    private function scope(string $tab, $user, Collection $projects): Builder
    {
        $query = WorkItem::query()
            ->active()
            ->whereIn('project_id', $projects->pluck('id'));

        return match ($tab) {
            'assigned' => $query->whereHas('assignees', fn ($a) => $a->whereKey($user->id)),
            'created' => $query->where('created_by', $user->id),
            // Project-level, because that is the subscription this application has: §13's
            // project_subscribers is "who wants to hear about this project". There is no
            // per-work-item subscription to read instead (your-work.md D-Y1).
            'subscribed' => $query->whereIn('project_id', DB::table('project_subscribers')
                ->where('user_id', $user->id)->select('project_id')),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /**
     * The count each list tab would show, so the tab bar can carry it without loading four
     * more lists. Counted through the same scopes the lists use, so a number can never
     * disagree with the list behind it.
     *
     * @param  Collection<int, Project>  $projects
     * @return array<string, int>
     */
    private function counts($user, Collection $projects): array
    {
        return collect(['assigned', 'created', 'subscribed'])
            ->mapWithKeys(fn (string $tab) => [$tab => $this->scope($tab, $user, $projects)->count()])
            ->all();
    }

    /**
     * What this person did, newest first (your-work.md D-Y2).
     *
     * Their own actions — not what happened to their items. "Your work" is a record of the
     * work, and an inbox of other people's edits is a different feature with a different name.
     *
     * @param  Collection<int, Project>  $projects
     * @return array<int, array<string, mixed>>
     */
    private function activity($user, Collection $projects): array
    {
        return WorkItemActivity::query()
            ->where('actor_id', $user->id)
            // Bounded by reach, like everything else here: an entry whose work item now lives
            // in a project this user can no longer open must not keep showing its old values.
            ->whereIn('work_item_id', WorkItem::query()
                ->whereIn('project_id', $projects->pluck('id'))
                ->select('id'))
            ->with(['actor', 'workItem:id,identifier,title,project_id'])
            ->latest('id')
            ->limit((int) config('projects.your_work_activity_size'))
            ->get()
            ->map(fn (WorkItemActivity $a) => [
                'id' => $a->id,
                'event' => $a->event,
                'field' => $a->field,
                'old_value' => $a->old_value,
                'new_value' => $a->new_value,
                'created_at' => $a->created_at?->toIso8601String(),
                'actor' => $a->actor ? [
                    'id' => $a->actor->id, 'name' => $a->actor->displayName(),
                    'initial' => $a->actor->initial(), 'avatar_url' => $a->actor->avatar_url,
                ] : null,
                // Which item, and how to open it — an activity line nobody can follow back to
                // the thing it happened to is a log, not a feed.
                'item' => $a->workItem ? [
                    'id' => $a->workItem->id,
                    'identifier' => $a->workItem->identifier,
                    'title' => $a->workItem->title,
                    'url' => route('projects.work-items.show', [
                        'project' => $a->workItem->project_id, 'workItem' => $a->workItem->id,
                    ]),
                ] : null,
            ])->all();
    }
}
