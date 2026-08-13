<?php

namespace App\Services;

use App\Models\WorkItemSubscriber;
use App\Models\WorkItemVote;
use Illuminate\Support\Facades\Auth;

/**
 * Vote counts and the viewer's own position, for a set of work items at once.
 *
 * Batched for the same reason WorkItemBlockers and WorkItemStatusUpdates are: the toolbar
 * needs this on every row it paints, and asking per row would be three queries per work item —
 * 750 on a full list. Three queries total, whatever the list's length.
 *
 * "The viewer's own position" is deliberately part of the answer rather than something the
 * client works out: whether YOU voted is not derivable from a count, and the alternative is
 * shipping every voter's id to the browser to find yourself in it.
 */
class WorkItemReactions
{
    /**
     * @param  array<int, int>  $ids
     * @return array<int, array{votes: array{up: int, down: int}, my_vote: ?string, subscribed: bool}>
     */
    public function for(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $userId = Auth::id();

        $counts = WorkItemVote::query()
            ->whereIn('work_item_id', $ids)
            ->selectRaw('work_item_id, value, count(*) as total')
            ->groupBy('work_item_id', 'value')
            ->get();

        $mine = WorkItemVote::query()
            ->whereIn('work_item_id', $ids)
            ->where('user_id', $userId)
            ->pluck('value', 'work_item_id');

        $subscribed = WorkItemSubscriber::query()
            ->whereIn('work_item_id', $ids)
            ->where('user_id', $userId)
            ->pluck('work_item_id')
            ->flip();

        $out = [];
        foreach ($ids as $id) {
            $out[$id] = [
                'votes' => ['up' => 0, 'down' => 0],
                'my_vote' => $mine[$id] ?? null,
                'subscribed' => $subscribed->has($id),
            ];
        }

        foreach ($counts as $row) {
            if (isset($out[$row->work_item_id])) {
                $out[$row->work_item_id]['votes'][$row->value] = (int) $row->total;
            }
        }

        return $out;
    }

    /** The same shape for one item — what the toolbar's own endpoints answer with. */
    public function one(int $id): array
    {
        return $this->for([$id])[$id];
    }
}
