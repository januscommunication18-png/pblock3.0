<?php

namespace App\Services;

use App\Models\WorkItemRelation;

/**
 * How many UNRESOLVED blockers each work item has (Work Items §27–§29).
 *
 * A blocker that is itself done or cancelled is not holding anything up, so it does not
 * count — otherwise an item stays flagged blocked forever after the thing blocking it
 * shipped.
 *
 * Extracted from WorkItemController so the Cycles screen's work item grid can show the same
 * Blocked marker as the project's work item list. A row that reads "Blocked" on one screen
 * and not the other is worse than not showing it at all.
 */
class WorkItemBlockers
{
    /**
     * One grouped query for a whole list rather than one per row.
     *
     * @param  array<int, int>  $itemIds
     * @return array<int, int> work item id => open blocker count
     */
    public function counts(array $itemIds): array
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
}
