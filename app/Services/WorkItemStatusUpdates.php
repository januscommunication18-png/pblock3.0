<?php

namespace App\Services;

use App\Models\WorkItemUpdate;

/**
 * The current status update on each work item, when it is one that needs attention (§8).
 *
 * Only At Risk and Off Track are reported. On Track is the ordinary case, and a badge on every
 * row saying "fine" is noise that makes the two that are NOT fine harder to spot — which is
 * the whole reason for showing them in the list.
 *
 * "Current" means the most recent update, not the most recent *concerning* one: an item that
 * was At Risk last week and On Track today is on track, and must stop being flagged. Getting
 * that backwards would leave rows permanently marked after they recovered.
 */
class WorkItemStatusUpdates
{
    /**
     * Two queries for a whole list, never one per row.
     *
     * @param  array<int, int>  $itemIds
     * @return array<int, array{status:string, label:string, comment:?string}> keyed by work item id
     */
    public function currentConcerns(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        // The latest update per item. Ids are monotonic, so MAX(id) is the newest without
        // needing a window function — which SQLite and older MySQL do not share.
        $latestIds = WorkItemUpdate::query()
            ->whereIn('work_item_id', $itemIds)
            ->groupBy('work_item_id')
            ->selectRaw('MAX(id) as id')
            ->pluck('id')
            ->all();

        if ($latestIds === []) {
            return [];
        }

        $excerpt = app(RichTextSanitizer::class);

        return WorkItemUpdate::query()
            ->whereIn('id', $latestIds)
            ->whereIn('status', [WorkItemUpdate::STATUS_AT_RISK, WorkItemUpdate::STATUS_OFF_TRACK])
            ->get()
            ->mapWithKeys(fn (WorkItemUpdate $u) => [$u->work_item_id => [
                'status' => $u->status,
                'label' => $u->label(),
                // Plain text: the update is rich text, and this goes into a tooltip attribute.
                'comment' => $excerpt->excerpt($u->content, 240),
            ]])
            ->all();
    }
}
