<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectView;
use App\Models\User;
use App\Models\WorkItem;
use App\Policies\WorkItemPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * A page of rows for a View's grid (Views §7.1, §24).
 *
 * §24 asks that a large project not be loaded into the browser in one go, so this returns one
 * page at a time and the grid asks for the next as the user scrolls. Search and sort are
 * applied in SQL for the same reason: filtering client-side would mean shipping every row to
 * filter it, which is the thing being avoided.
 *
 * ## Why the row is the Work Items card
 *
 * Rows are built by `WorkItemScreenPayload::card()` — the same payload the Work Items list
 * renders — and then extended with whatever the View's *configured* columns additionally need.
 * Two reasons. It makes the chips identical in both screens without a second serializer to
 * keep in step, and it inherits the tuned eager loads rather than growing a fresh set of N+1s.
 *
 * §24's "return only fields needed by the configured View" is honoured where it costs
 * something — the extra relations below are loaded only when a column actually asks for them —
 * but not by trimming the base card. A user who can open this View can already open every one
 * of these work items, so paring the payload would save bytes and hide nothing.
 *
 * That calculus changes for Slice 3's published links, where the audience is anonymous and
 * §18.2 requires that no field beyond the configured columns leaves the server. The public
 * endpoint therefore projects rows down to the configured columns rather than reusing this.
 */
class ViewGridPayload
{
    public function __construct(
        private readonly WorkItemScreenPayload $screen,
        private readonly ViewFieldCatalog $catalog,
    ) {}

    /**
     * Columns that can be sorted in SQL, mapped to the expression that does it.
     *
     * A column not listed here is not offered as sortable rather than silently ignored — a
     * sort control that does nothing is worse than one that is absent.
     */
    private const SORTABLE = [
        'work_item.identifier' => 'sequence_no',
        'work_item.title' => 'title',
        'work_item.start_date' => 'start_date',
        'work_item.due_date' => 'due_date',
        'work_item.created_at' => 'created_at',
        'work_item.updated_at' => 'updated_at',
        'work_item.priority' => 'priority',
        'work_item.state' => 'state_id',
    ];

    /**
     * @return array{rows: array<int, array<string, mixed>>, page: int, per_page: int, has_more: bool, total: int}
     */
    public function rows(ProjectView $view, Project $project, int $page = 1, ?string $search = null, ?string $sort = null, string $direction = 'asc'): array
    {
        $user = Auth::user();
        $perPage = (int) config('projects.view_page_size');
        $page = max(1, $page);

        $query = $this->baseQuery($project, $user, $view);

        $this->applySearch($query, $search);

        // Counted BEFORE the sort and the window are applied — and only on the first page. The
        // count is for the header; re-running it on every scroll is the expensive half of the
        // work the paging exists to avoid.
        $total = $page === 1 ? (clone $query)->toBase()->getCountForPagination() : 0;

        $this->applySort($query, $sort, $direction);

        // One extra row is fetched, never returned: it is how the grid knows whether to ask
        // for another page without a second COUNT over a large table on every scroll.
        $items = $query->skip(($page - 1) * $perPage)->take($perPage + 1)->get();

        $hasMore = $items->count() > $perPage;
        $items = $items->take($perPage);

        return [
            // `cards()` drops rows the caller may not see, which is what keeps a project set
            // to "assigned work items only" restricted inside a View too (§11.3).
            'rows' => $this->extend($this->screen->cards($items), $items, $view),
            'page' => $page,
            'per_page' => $perPage,
            'has_more' => $hasMore,
            'total' => $total,
        ];
    }

    /**
     * One row, in the same shape a page of rows uses.
     *
     * An inline edit answers with this rather than with a bare card: a View showing Epic
     * status or a Cycle's dates would otherwise blank those cells the moment the row was
     * replaced, because the extras only travel with `rows()`.
     *
     * @return array<string, mixed>
     */
    public function row(ProjectView $view, WorkItem $item): array
    {
        $items = collect([$item->loadMissing($this->eagerLoads($view))]);

        return $this->extend($this->screen->cards($items), $items, $view)[0] ?? [];
    }

    /** The sortable column keys, for the grid's header controls. @return array<int, string> */
    public function sortableKeys(): array
    {
        return array_keys(self::SORTABLE);
    }

    /** @return Builder<WorkItem> */
    private function baseQuery(Project $project, User $user, ProjectView $view): Builder
    {
        // §10 of the Work Items spec: a project restricted to assigned items filters the query
        // itself, not the rendering. A View must not become the way around that.
        $assignedOnly = ! app(WorkItemPolicy::class)->canSeeEveryItem($user, $project);

        return WorkItem::query()
            ->forProject($project->id)
            ->active()
            ->when($assignedOnly, fn (Builder $q) => $q->whereHas('assignees', fn ($a) => $a->whereKey($user->id)))
            ->with($this->eagerLoads($view));
    }

    /**
     * What to eager-load, decided by the columns this View actually has (§24).
     *
     * The base set is what `card()` reads; the conditional ones are the relations only certain
     * columns need. A View without an Epic-lead column does not pay for the users behind every
     * epic on the page.
     *
     * @return array<int|string, mixed>
     */
    private function eagerLoads(ProjectView $view): array
    {
        $keys = $view->columns->map(fn ($c) => $c->key())->all();

        $loads = [
            'state', 'assignees', 'labels', 'parent:id,identifier,title',
            'cycle', 'epic', 'estimateValue', 'modules', 'creator',
        ];

        if (in_array('epic.lead', $keys, true)) {
            $loads[] = 'epic.lead';
        }

        if (in_array('module.lead', $keys, true)) {
            $loads[] = 'modules.lead';
        }

        return $loads;
    }

    /**
     * Add the fields the base card does not carry, for the columns that ask for them.
     *
     * Keyed under `view` so nothing here can collide with a card key now or later — the card
     * belongs to the Work Items screen and will keep changing on its own schedule.
     *
     * @param  array<int, array<string, mixed>>  $cards
     * @param  Collection<int, WorkItem>  $items
     * @return array<int, array<string, mixed>>
     */
    private function extend(array $cards, $items, ProjectView $view): array
    {
        $keys = $view->columns->map(fn ($c) => $c->key())->all();
        $byId = $items->keyBy('id');

        return array_map(function (array $card) use ($byId, $keys) {
            $item = $byId->get($card['id']);
            $extra = [];

            if ($item === null) {
                return $card + ['view' => $extra];
            }

            // §25: a work item pointing at a deleted epic/cycle/module shows an empty cell.
            // Every read below is null-safe for exactly that reason — the View must not break
            // because a related record went away.
            if (in_array('epic.status', $keys, true)) {
                $extra['epic.status'] = $item->epic?->status;
            }

            if (in_array('epic.lead', $keys, true)) {
                $extra['epic.lead'] = $this->person($item->epic?->lead);
            }

            if (in_array('cycle.start_date', $keys, true)) {
                $extra['cycle.start_date'] = $item->cycle?->start_date?->format('Y-m-d');
            }

            if (in_array('cycle.end_date', $keys, true)) {
                $extra['cycle.end_date'] = $item->cycle?->end_date?->format('Y-m-d');
            }

            if (in_array('module.lead', $keys, true)) {
                $extra['module.lead'] = $item->modules
                    ->map(fn ($m) => $this->person($m->lead))
                    ->filter()
                    ->values()
                    ->all();
            }

            if (in_array('member.created_by', $keys, true)) {
                $extra['member.created_by'] = $this->person($item->creator);
            }

            return $card + ['view' => $extra];
        }, $cards);
    }

    /** @return array<string, mixed>|null */
    private function person(?User $user): ?array
    {
        return $user === null ? null : [
            'id' => $user->id,
            'name' => $user->displayName(),
            'initial' => $user->initial(),
            'avatar_url' => $user->avatar_url,
        ];
    }

    /**
     * §12.1's search, over the fields a user would actually type into it.
     *
     * The identifier is matched too, because "TESTI-12" is how people refer to a work item out
     * loud — searching for it and getting nothing would read as the search being broken.
     *
     * @param  Builder<WorkItem>  $query
     */
    private function applySearch(Builder $query, ?string $search): void
    {
        $search = trim((string) $search);

        if ($search === '') {
            return;
        }

        // Escaped so a user typing % or _ searches for those characters rather than turning
        // their query into a wildcard that matches everything.
        $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $search).'%';

        // The ESCAPE clause is not optional, and its absence is invisible on MySQL.
        //
        // MySQL treats backslash as LIKE's escape character by default, so `\%` works there
        // and a search for "100%" behaves. SQLite — which this suite runs on — has NO default
        // escape character, so `\%` is a literal backslash followed by a wildcard and matches
        // nothing at all. Naming the escape character explicitly is portable across MySQL,
        // SQLite and Postgres alike, which is what CLAUDE.md §6 asks of the schema and is no
        // less true of a query.
        $query->where(function (Builder $q) use ($term) {
            $q->whereRaw("title LIKE ? ESCAPE '\\'", [$term])
                ->orWhereRaw("identifier LIKE ? ESCAPE '\\'", [$term]);
        });
    }

    /** @param  Builder<WorkItem>  $query */
    private function applySort(Builder $query, ?string $sort, string $direction): void
    {
        $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';
        $column = self::SORTABLE[$sort] ?? null;

        if ($column === null) {
            // The list's own order, which is the order a work item was created in.
            $query->orderBy('sequence_no');

            return;
        }

        if ($column === 'priority') {
            // A string vocabulary sorted alphabetically reads as nonsense — "high, low,
            // medium, none, urgent". Ordered by the config's own sequence instead, which is
            // urgent → none, so ascending means most urgent first.
            $order = array_keys(config('projects.work_item_priorities'));
            $query->orderByRaw($this->fieldOrder('priority', $order, $direction));
        } else {
            $query->orderBy($column, $direction);
        }

        // A stable tiebreak. Without it, rows sharing a value can come back in a different
        // order on each page request, and a row would appear twice across two pages while
        // another never appeared at all.
        $query->orderBy('sequence_no');
    }

    /**
     * ORDER BY over a fixed vocabulary, as a portable CASE rather than MySQL's FIELD().
     *
     * CLAUDE.md §6 asks for portable migrations and the same instinct applies to queries:
     * FIELD() does not exist on Postgres or SQLite, and the suite runs on SQLite.
     *
     * @param  array<int, string>  $order
     */
    private function fieldOrder(string $column, array $order, string $direction): string
    {
        $cases = '';

        foreach (array_values($order) as $i => $value) {
            // Bound values are not available inside orderByRaw here, so the vocabulary is
            // escaped explicitly. It comes from config rather than from a request, but a
            // config value reaching SQL unescaped is a habit worth not forming.
            $cases .= ' when '.$this->quote($column).' = '.$this->quote($value, literal: true)." then {$i}";
        }

        return 'case'.$cases.' else '.count($order).' end '.$direction;
    }

    private function quote(string $value, bool $literal = false): string
    {
        $clean = preg_replace('/[^a-z0-9_]/i', '', $value) ?? '';

        return $literal ? "'{$clean}'" : $clean;
    }
}
