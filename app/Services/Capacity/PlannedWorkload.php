<?php

namespace App\Services\Capacity;

use App\Models\WorkItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Estimated work assigned to people, converted to hours and placed in time (§17–§24).
 *
 * Three rules do the real work here, all of them from docs/features/work-capacity.md:
 *
 *   CAP-D4  An item's hours are SPREAD across its own span, not charged whole to every week it
 *           touches. A two-week 16h item is ~8h of this week, not 16h of each.
 *   CAP-D6  If any child carries an estimate, the parent's own estimate is ignored — so a
 *           parent and its sub-tasks can never both count (§23).
 *   CAP-D9  Several assignees split the hours equally (§22).
 *
 * What it will NOT do is guess. Unestimated, unmapped and undated work is counted and reported
 * separately (CAP-D2/CAP-D5), never folded in as zero — a total that quietly omits a third of
 * the board is worse than no total, because it looks like an answer.
 */
class PlannedWorkload
{
    /**
     * @param  array<int, int>|null  $projectIds  limit to these projects, or null for all
     * @return array{
     *     hours: array<int, float>,
     *     unestimated: array<int, int>,
     *     undated: array<int, int>,
     *     items: array<int, int>
     * }
     */
    public function forWindow(CapacityWindow $window, ?array $projectIds = null): array
    {
        $hours = [];
        $unestimated = [];
        $undated = [];
        $items = [];

        $candidates = $this->candidates($window, $projectIds);
        $rolledUp = $this->parentsSupersededByChildren($candidates);

        foreach ($candidates as $item) {
            $assignees = $item->assignees->pluck('id')->all();

            // Unassigned work is real work, but it is nobody's capacity yet. It belongs to a
            // backlog report, not to this one.
            if ($assignees === []) {
                continue;
            }

            foreach ($assignees as $userId) {
                $items[$userId] = ($items[$userId] ?? 0) + 1;
            }

            // §23: the parent's estimate describes the same work its estimated children do.
            if (in_array((int) $item->id, $rolledUp, true)) {
                continue;
            }

            if ($item->capacity_hours === null) {
                foreach ($assignees as $userId) {
                    $unestimated[$userId] = ($unestimated[$userId] ?? 0) + 1;
                }

                continue;
            }

            $span = $this->spanOf($item);

            if (! $span) {
                foreach ($assignees as $userId) {
                    $undated[$userId] = ($undated[$userId] ?? 0) + 1;
                }

                continue;
            }

            // CAP-D9 — equal split. Custom allocation is a later phase (§22).
            $share = (float) $item->capacity_hours / count($assignees);

            foreach ($assignees as $userId) {
                $hours[$userId] = round(($hours[$userId] ?? 0) + $this->portionInWindow($share, $span, $window), 2);
            }
        }

        return ['hours' => $hours, 'unestimated' => $unestimated, 'undated' => $undated, 'items' => $items];
    }

    /**
     * Assigned, live work that touches this window.
     *
     * The date filter is applied in SQL so a workspace with years of history reads only the
     * weeks being asked about. Undated items are pulled in too — they cannot contribute hours,
     * but CAP-D5 says they must be COUNTED, and an item excluded by the query is an item that
     * cannot be reported as missing.
     *
     * @param  array<int, int>|null  $projectIds
     * @return Collection<int, WorkItem>
     */
    private function candidates(CapacityWindow $window, ?array $projectIds): Collection
    {
        return WorkItem::query()
            ->active()
            ->incomplete()
            ->whereHas('assignees')
            ->with('assignees:id')
            ->when($projectIds !== null, fn ($q) => $q->whereIn('project_id', $projectIds))
            ->where(function ($q) use ($window) {
                // Undated work contributes no hours but must still be COUNTED (CAP-D5), so it
                // has to survive the query — an item filtered out here cannot be reported as
                // missing, and the total would look complete when it is not.
                $q->where(fn ($undated) => $undated->whereNull('start_date')->whereNull('due_date'))
                    // COALESCE rather than two date columns: an item with only a due date is
                    // placed on that day (see spanOf), and the filter has to agree with that
                    // or such items vanish from a window they genuinely fall inside.
                    ->orWhere(fn ($dated) => $dated
                        ->whereRaw('COALESCE(start_date, due_date) <= ?', [$window->end->toDateString()])
                        ->whereRaw('COALESCE(due_date, start_date) >= ?', [$window->start->toDateString()]));
            })
            ->select(['id', 'project_id', 'parent_id', 'capacity_hours', 'start_date', 'due_date'])
            ->get();
    }

    /**
     * Parents whose estimate is superseded by their children's (§23 / CAP-D6).
     *
     * One query rather than a per-item lookup: the set is small (only parents present in the
     * candidate list matter) and n+1 here would be one query per row of the report.
     *
     * @param  Collection<int, WorkItem>  $candidates
     * @return array<int, int>
     */
    private function parentsSupersededByChildren(Collection $candidates): array
    {
        $ids = $candidates->pluck('id')->all();

        if ($ids === []) {
            return [];
        }

        return WorkItem::query()
            ->whereIn('parent_id', $ids)
            ->whereNotNull('capacity_hours')
            ->distinct()
            ->pluck('parent_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** The item's own dates as a window, or null when it has neither (CAP-D5). */
    private function spanOf(WorkItem $item): ?CapacityWindow
    {
        if (! $item->start_date && ! $item->due_date) {
            return null;
        }

        // One date is enough to place the work: an item with only a due date is a day's worth
        // of work landing on that day, which is more useful than discarding it entirely.
        $start = $item->start_date ?: $item->due_date;
        $end = $item->due_date ?: $item->start_date;

        return new CapacityWindow(CarbonImmutable::parse($start), CarbonImmutable::parse($end));
    }

    /**
     * The share of an item's hours that falls inside the reporting window (CAP-D4).
     *
     * Spread evenly across the item's own days, then counted for the overlapping ones. Uses
     * calendar days rather than working days on purpose: the item's span is a commitment about
     * the calendar, and dividing by working days would make the same item worth more per day
     * to somebody on a four-day week — inflating exactly the person least able to absorb it.
     */
    private function portionInWindow(float $hours, CapacityWindow $span, CapacityWindow $window): float
    {
        $overlap = $span->intersect($window);

        if (! $overlap) {
            return 0.0;
        }

        $spanDays = count($span->days());
        $overlapDays = count($overlap->days());

        return $spanDays === 0 ? 0.0 : $hours * ($overlapDays / $spanDays);
    }
}
