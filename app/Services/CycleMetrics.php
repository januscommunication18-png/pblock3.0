<?php

namespace App\Services;

use App\Models\Cycle;
use App\Models\WorkItem;
use Illuminate\Support\Collection;

/**
 * Progress figures for the Cycles page (Cycles §5.1 / §7.1).
 *
 * The landing page renders every cycle with a percentage and the active one with a full
 * breakdown, so the counts are gathered for a whole list in ONE grouped query rather than per
 * cycle — otherwise opening a project with a dozen cycles costs a dozen round trips.
 *
 * "Completed" means the work item's state sits in the `completed` group, the same stable key
 * the work item list groups by. Cancelled work is counted separately and deliberately left
 * out of the completion percentage: cancelling a task is not finishing it.
 */
class CycleMetrics
{
    /** Every group a work item state can belong to, in the order the breakdown reads. */
    private const GROUPS = ['completed', 'started', 'unstarted', 'backlog', 'cancelled'];

    /**
     * Breakdown per cycle id, for a set of cycles.
     *
     * @param  Collection<int, Cycle>|array<int, Cycle>  $cycles
     * @return array<int, array<string, int|float>>
     */
    public function forCycles(iterable $cycles): array
    {
        $ids = collect($cycles)->pluck('id')->all();

        if ($ids === []) {
            return [];
        }

        $rows = WorkItem::query()
            ->whereIn('work_items.cycle_id', $ids)
            // Qualified: the join brings a second table into scope, and an unqualified column
            // name here is an ambiguity waiting to happen.
            ->whereNull('work_items.archived_at')
            ->leftJoin('project_item_states', 'project_item_states.id', '=', 'work_items.state_id')
            // `group` is a reserved word. Selected through the builder rather than selectRaw
            // so the grammar quotes it for whichever engine is underneath (CLAUDE.md §6 —
            // portable migrations and queries, no DB-specific SQL).
            ->select(['work_items.cycle_id', 'project_item_states.group as state_group'])
            ->selectRaw('COUNT(*) as total')
            ->groupBy('work_items.cycle_id', 'project_item_states.group')
            ->get();

        $out = [];
        foreach ($ids as $id) {
            $out[$id] = $this->shape($rows->where('cycle_id', $id));
        }

        return $out;
    }

    /** Breakdown for a single cycle. */
    public function forCycle(Cycle $cycle): array
    {
        return $this->forCycles([$cycle])[$cycle->id] ?? $this->shape(collect());
    }

    /**
     * @param  Collection<int, object>  $rows  Rows of {state_group, total} for one cycle.
     * @return array<string, int|float>
     */
    private function shape(Collection $rows): array
    {
        $counts = [];
        foreach (self::GROUPS as $group) {
            $counts[$group] = (int) $rows->where('state_group', $group)->sum('total');
        }

        // A state with no group — or an item with no state at all — still counts as scope, so
        // total is the sum of the rows, not the sum of the buckets.
        $total = (int) $rows->sum('total');
        $counts['scope'] = $total;
        $counts['progress'] = $total > 0 ? (int) round($counts['completed'] / $total * 100) : 0;

        return $counts;
    }
}
