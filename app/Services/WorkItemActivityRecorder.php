<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkItemActivity;
use App\Models\WorkItemVote;

/**
 * Writes a work item's activity feed (Work Items §6).
 *
 * The single funnel for audit events — every mutation should come through here rather than
 * writing WorkItemActivity directly, so there is exactly one place to extend when the
 * remaining feeds (subscriptions) land.
 *
 * Display values are resolved and stored at write time. A feed that re-resolves names on
 * read would silently rewrite history when a state or label is renamed or deleted, which
 * defeats the point of an audit record.
 */
class WorkItemActivityRecorder
{
    /** What an absent vote is stored as on either side of a vote audit row. */
    public const VOTE_NONE = 'none';

    /**
     * Log the work item's creation, with a snapshot of the properties it was created with.
     *
     * Called inside WorkItemCreator's transaction, so a work item can never exist without
     * its creation entry — activity is not something that can be backfilled later.
     */
    public function created(WorkItem $item, ?User $actor): WorkItemActivity
    {
        return $this->record($item, $actor, WorkItemActivity::EVENT_CREATED, [
            'meta' => ['snapshot' => $this->snapshot($item)],
        ]);
    }

    /**
     * Log a vote being cast, switched or withdrawn (work item voting spec).
     *
     * `none` is written rather than null on either side, because "no vote" is a real state
     * of the audit trail: null would be indistinguishable from a row that never recorded
     * that side at all, and History filters those out. The 👍 / 👎 labels are frozen in
     * `meta` at write time for the same reason every other display value is (see the class
     * note) — History renders before → after from them.
     */
    public function voteChanged(WorkItem $item, ?User $actor, ?string $old, ?string $new): WorkItemActivity
    {
        return $this->record($item, $actor, WorkItemActivity::EVENT_VOTE_CHANGED, [
            'field' => 'vote',
            'old_value' => $old ?? self::VOTE_NONE,
            'new_value' => $new ?? self::VOTE_NONE,
            'meta' => [
                'old_label' => $this->voteLabel($old),
                'new_label' => $this->voteLabel($new),
            ],
        ]);
    }

    /** How a vote reads in the feed. */
    private function voteLabel(?string $value): string
    {
        return match ($value) {
            WorkItemVote::UP => '👍 Up',
            WorkItemVote::DOWN => '👎 Down',
            default => 'None',
        };
    }

    /**
     * @param  array{field?:string, old_value?:?string, new_value?:?string, meta?:?array}  $attributes
     */
    public function record(WorkItem $item, ?User $actor, string $event, array $attributes = []): WorkItemActivity
    {
        return WorkItemActivity::create([
            'work_item_id' => $item->id,
            'actor_id' => $actor?->id,
            'event' => $event,
            'field' => $attributes['field'] ?? null,
            'old_value' => $attributes['old_value'] ?? null,
            'new_value' => $attributes['new_value'] ?? null,
            'meta' => $attributes['meta'] ?? null,
        ]);
    }

    /**
     * The properties a work item was created with, resolved to display values.
     *
     * @return array<string, mixed>
     */
    private function snapshot(WorkItem $item): array
    {
        $item->loadMissing(['state', 'assignees', 'labels', 'parent']);

        return [
            'title' => $item->title,
            'state' => $item->state?->name,
            'priority' => $item->priority,
            'start_date' => $item->start_date?->format('Y-m-d'),
            'due_date' => $item->due_date?->format('Y-m-d'),
            'parent' => $item->parent?->identifier,
            'assignees' => $item->assignees->map(fn (User $u) => $u->displayName())->values()->all(),
            'labels' => $item->labels->pluck('name')->values()->all(),
        ];
    }
}
