<?php

namespace App\Services\Capacity;

use App\Models\EstimateValue;
use App\Models\ProjectEstimation;
use App\Models\WorkItem;
use Illuminate\Support\Facades\DB;

/**
 * Keeps `work_items.capacity_hours` in step with the estimate on it (CAP-D3).
 *
 * The snapshot exists for two reasons, and both are why this class rather than a join:
 *
 *   Reports read one column instead of joining `estimate_values` for every row.
 *   §47 holds — changing "L" from 12h to 16h today must not rewrite last quarter's report.
 *
 * So the rule is deliberately asymmetric: OPEN work is re-snapshotted when a mapping changes,
 * because it has not happened yet and should be planned with today's numbers. COMPLETED work
 * keeps what it was planned with, because that is what the plan actually said at the time.
 */
class CapacitySnapshotWriter
{
    /** Write the snapshot for one work item from whatever estimate it now carries. */
    public function forItem(WorkItem $item): void
    {
        $hours = $item->estimate_value_id
            ? EstimateValue::query()->find($item->estimate_value_id)?->capacityHours()
            : null;

        // Normalized before comparing: the column is a decimal cast, so an unchanged value
        // arrives as the string "8.00" and a strict check against float 8.0 would rewrite
        // every row on every save.
        $current = $item->capacity_hours === null ? null : (float) $item->capacity_hours;

        if ($current === $hours) {
            return;
        }

        $item->forceFill(['capacity_hours' => $hours])->saveQuietly();
    }

    /**
     * Re-snapshot every OPEN work item using a value whose hours have just changed.
     *
     * Bulk, because renaming a size in a busy workspace can touch thousands of rows and this
     * runs inside the settings request that changed it.
     */
    public function afterValueChanged(EstimateValue $value): int
    {
        return WorkItem::query()
            ->incomplete()
            ->where('estimate_value_id', $value->id)
            ->update(['capacity_hours' => $value->capacityHours()]);
    }

    /**
     * Re-snapshot everything open in a project — used after a bulk mapping edit.
     */
    public function afterEstimationChanged(ProjectEstimation $estimation): void
    {
        $values = EstimateValue::query()
            ->where('project_estimation_id', $estimation->id)
            ->get();

        DB::transaction(function () use ($values) {
            foreach ($values as $value) {
                $this->afterValueChanged($value);
            }
        });
    }
}
