<?php

namespace App\Observers;

use App\Models\EstimateValue;
use App\Models\WorkItem;

/**
 * Keeps `work_items.capacity_hours` true to the estimate on the row (CAP-D3, §35).
 *
 * AN OBSERVER, not a call in the two services that looked like the write path. `estimate_value_id`
 * is written from at least four places — WorkItemCreator, WorkItemUpdater, the view-grid cell
 * editor and quick create — and any one that forgot to snapshot would leave a row whose reported
 * hours quietly disagree with its own estimate. A stale capacity figure is worse than a missing
 * one: it still adds up, so nothing looks wrong.
 *
 * Runs on `saving`, so the value is part of the same write rather than a second one — no extra
 * query, and no window where the row exists with the wrong hours.
 */
class WorkItemCapacityObserver
{
    public function saving(WorkItem $item): void
    {
        // Only when the estimate actually moved. Every other edit — a title, a due date, a
        // drag between states — would otherwise pay for a lookup it has no use for.
        if (! $item->isDirty('estimate_value_id')) {
            return;
        }

        $item->capacity_hours = $item->estimate_value_id
            ? EstimateValue::query()->find($item->estimate_value_id)?->capacityHours()
            : null;
    }
}
