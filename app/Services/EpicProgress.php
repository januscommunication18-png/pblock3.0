<?php

namespace App\Services;

use App\Models\Epic;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Epic progress, from the work items assigned to it (Epic §13).
 *
 * `Progress % = completed / total × 100`, counted by **work item count** — §13 picks count for
 * Phase 1, and there are no estimates or story points in this app to weigh by anyway.
 * "Completed" means the work item's state sits in the project's **completed group**, never a
 * state named "Done": states are user-configurable, so matching on a name would break the
 * moment somebody renamed one.
 *
 * Two deliberate differences from ModuleProgress, both from §13:
 *
 *  - **Cancelled work leaves the denominator.** A module counts it; an epic does not. So
 *    cancelling scope raises an epic's progress rather than capping it below 100% forever.
 *  - **Overdue is reported.** §8's Overview asks for it, and it is a different question from
 *    the state breakdown: work that is late, not work that is unfinished.
 *
 * Counted for a whole list in one grouped query rather than per epic, so a project with thirty
 * epics costs one round trip and not thirty.
 */
class EpicProgress
{
    /** The state groups reported in the breakdown (§8), in reading order. */
    private const GROUPS = ['completed', 'started', 'unstarted', 'backlog', 'cancelled'];

    /**
     * @param  Collection<int, Epic>|array<int, Epic>  $epics
     * @return array<int, array<string, int>> keyed by epic id
     */
    public function forEpics(iterable $epics): array
    {
        $ids = collect($epics)->pluck('id')->all();

        if ($ids === []) {
            return [];
        }

        $rows = DB::table('work_items')
            ->leftJoin('project_item_states', 'project_item_states.id', '=', 'work_items.state_id')
            ->whereIn('work_items.epic_id', $ids)
            // An archived work item is not part of the current body of work.
            ->whereNull('work_items.archived_at')
            // `group` is a reserved word; select() lets the grammar quote it, where a raw
            // string would be a syntax error on MySQL and SQLite alike.
            ->select('work_items.epic_id', 'project_item_states.group as state_group')
            ->selectRaw('COUNT(*) as tally')
            ->groupBy('work_items.epic_id', 'project_item_states.group')
            ->get();

        $overdue = $this->overdueCounts($ids);

        $out = [];
        foreach ($ids as $id) {
            $out[$id] = $this->shape($rows->where('epic_id', $id), (int) ($overdue[$id] ?? 0));
        }

        return $out;
    }

    public function forEpic(Epic $epic): array
    {
        return $this->forEpics([$epic])[$epic->id] ?? $this->shape(collect(), 0);
    }

    /**
     * Work items past their due date that nobody has finished or cancelled (§8).
     *
     * @param  array<int, int>  $ids
     * @return array<int, int>
     */
    private function overdueCounts(array $ids): array
    {
        return DB::table('work_items')
            ->leftJoin('project_item_states', 'project_item_states.id', '=', 'work_items.state_id')
            ->whereIn('work_items.epic_id', $ids)
            ->whereNull('work_items.archived_at')
            ->whereNotNull('work_items.due_date')
            ->whereDate('work_items.due_date', '<', now()->toDateString())
            // A finished or abandoned item cannot be late. Items with no state at all can:
            // nobody has said they are done.
            ->where(fn ($w) => $w
                ->whereNull('project_item_states.group')
                ->orWhereNotIn('project_item_states.group', ['completed', 'cancelled']))
            ->groupBy('work_items.epic_id')
            ->pluck(DB::raw('COUNT(*)'), 'work_items.epic_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /** @return array<string, int> */
    private function shape(Collection $rows, int $overdue): array
    {
        $counts = [];
        foreach (self::GROUPS as $group) {
            $counts[$group] = (int) $rows->where('state_group', $group)->sum('tally');
        }

        // §13: cancelled work items leave the denominator. Everything else counts, including
        // items with no state at all — those have certainly not been completed, and dropping
        // them would quietly flatter the number.
        $counts['cancelled_excluded'] = $counts['cancelled'];
        $total = (int) $rows->sum('tally') - $counts['cancelled'];

        $counts['total'] = $total;
        $counts['overdue'] = $overdue;
        // §13: an epic with no work items is 0%, not a division by zero.
        $counts['percent'] = $total > 0 ? (int) round($counts['completed'] / $total * 100) : 0;
        $counts['empty'] = $total === 0;

        return $counts;
    }
}
