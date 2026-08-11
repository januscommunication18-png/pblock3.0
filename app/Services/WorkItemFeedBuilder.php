<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkItemActivity;
use App\Models\WorkItemComment;
use App\Models\WorkItemTransition;
use App\Models\WorkItemUpdate;
use App\Models\WorkItemWorklog;

/**
 * Reads behind the seven collaboration tabs (Activity & Audit spec §5–§11).
 *
 * The tabs are different QUESTIONS about the same work item, not different copies of the same
 * list (§12/§29), and this class is where that distinction is kept honest:
 *
 * - **All** merges comments, activity, updates and worklogs by timestamp (§5.4) — never
 *   comments first and events afterwards.
 * - **Activity** is system events only; user comments are excluded by construction, because
 *   they are not activity rows to begin with (§6.1).
 * - **Transition** reads the transitions table and derives time-in-state from consecutive
 *   timestamps (§10.4).
 * - **History** is the same audit rows as Activity, rendered as before → after, and only for
 *   rows that actually carry a change of value (§11.1).
 *
 * Nothing here writes. Activity, history and transitions are produced by the services that
 * perform the change, never accepted from a client (§22.4).
 */
class WorkItemFeedBuilder
{
    /** §16.1 — feeds are paginated rather than unbounded. */
    private const PAGE_SIZE = 25;

    public function __construct(private readonly WorkItemRelationManager $relations) {}

    /**
     * Everything the collaboration area needs for one work item.
     *
     * @return array<string, mixed>
     */
    public function for(WorkItem $item): array
    {
        $comments = $this->comments($item);
        $activity = $this->activity($item);
        $updates = $this->updates($item);
        $worklogs = $this->worklogs($item);
        // Read once and reuse: History is a filter over the same audit rows, and the tracked
        // total is one number. Fetching either twice put duplicate queries behind every open.
        $minutes = $this->trackedMinutes($item);

        return [
            'all' => $this->merged($comments, $activity, $updates, $worklogs),
            'activity' => $activity,
            'comments' => $comments,
            'updates' => $updates,
            'worklogs' => [
                'entries' => $worklogs,
                'total_minutes' => $minutes,
                'total_label' => WorkItemWorklog::humanDuration($minutes),
            ],
            'transition' => $this->transitions($item),
            'history' => $this->history($activity),
        ];
    }

    /**
     * The combined feed (§5.4). Sorted newest first (§5.5) after merging, so a comment
     * posted between two property changes lands between them rather than in its own block.
     *
     * @return array<int, array<string, mixed>>
     */
    private function merged(array $comments, array $activity, array $updates, array $worklogs): array
    {
        $entries = collect($comments)->map(fn ($c) => $c + ['kind' => 'comment'])
            ->merge(collect($activity)->map(fn ($a) => $a + ['kind' => 'activity']))
            ->merge(collect($updates)->map(fn ($u) => $u + ['kind' => 'update']))
            ->merge(collect($worklogs)->map(fn ($w) => $w + ['kind' => 'worklog']));

        return $entries
            ->sortByDesc(fn ($e) => $e['created_at'] ?? '')
            ->take(self::PAGE_SIZE * 2)
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function comments(WorkItem $item): array
    {
        return WorkItemComment::query()
            ->where('work_item_id', $item->id)
            ->whereNull('parent_comment_id')
            ->with(['author', 'replies.author'])
            ->latest('id')
            ->limit(self::PAGE_SIZE)
            ->get()
            ->map(fn (WorkItemComment $c) => $this->commentRow($c) + [
                'replies' => $c->replies->map(fn (WorkItemComment $r) => $this->commentRow($r))->values()->all(),
            ])
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function commentRow(WorkItemComment $c): array
    {
        return [
            'id' => $c->id,
            'author' => $this->actor($c->author),
            'content' => $c->content,
            'edited' => $c->isEdited(),
            'created_at' => $c->created_at?->toIso8601String(),
        ];
    }

    /**
     * System events, newest first (§6). Rendering stays on the client, which already speaks
     * the field vocabulary — the server's job is to hand over what happened, resolved.
     *
     * @return array<int, array<string, mixed>>
     */
    private function activity(WorkItem $item): array
    {
        return WorkItemActivity::query()
            ->where('work_item_id', $item->id)
            ->with('actor')
            ->latest('id')
            ->limit(self::PAGE_SIZE)
            ->get()
            ->map(fn (WorkItemActivity $a) => [
                'id' => $a->id,
                'event' => $a->event,
                'field' => $a->field,
                'old_value' => $a->old_value,
                'new_value' => $a->new_value,
                'meta' => $a->meta,
                'actor' => $this->actor($a->actor),
                'created_at' => $a->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * History is Activity read as before → after (§11.1), so only rows that carry a value
     * change qualify — "created this work item" is something that happened, not something
     * that changed from one value to another. It takes the rows already fetched rather than
     * reading them again.
     *
     * @param  array<int, array<string, mixed>>  $activity
     * @return array<int, array<string, mixed>>
     */
    private function history(array $activity): array
    {
        return collect($activity)
            ->filter(fn ($a) => $a['field'] !== null && ($a['old_value'] !== null || $a['new_value'] !== null))
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function updates(WorkItem $item): array
    {
        return WorkItemUpdate::query()
            ->where('work_item_id', $item->id)
            ->with('author')
            ->latest('id')
            ->limit(self::PAGE_SIZE)
            ->get()
            ->map(fn (WorkItemUpdate $u) => [
                'id' => $u->id,
                'status' => $u->status,
                'status_label' => $u->label(),
                'content' => $u->content,
                'author' => $this->actor($u->author),
                'edited' => $u->edited_at !== null,
                // The snapshot as reported, not as things stand now (§8.6).
                'progress' => $u->total_subtasks
                    ? [
                        'percent' => $u->progress_percent,
                        'completed' => $u->completed_subtasks,
                        'total' => $u->total_subtasks,
                    ]
                    : null,
                'created_at' => $u->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function worklogs(WorkItem $item): array
    {
        return WorkItemWorklog::query()
            ->where('work_item_id', $item->id)
            ->with('user')
            ->orderByDesc('work_date')
            ->orderByDesc('id')
            ->limit(self::PAGE_SIZE)
            ->get()
            ->map(fn (WorkItemWorklog $w) => [
                'id' => $w->id,
                'user' => $this->actor($w->user),
                'user_id' => $w->user_id,
                'minutes' => $w->minutes_logged,
                'duration' => $w->duration(),
                'work_date' => $w->work_date?->format('Y-m-d'),
                'description' => $w->description,
                'created_at' => $w->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /** §9.8 — recalculated from the rows, never stored as a running total that can drift. */
    public function trackedMinutes(WorkItem $item): int
    {
        return (int) WorkItemWorklog::query()->where('work_item_id', $item->id)->sum('minutes_logged');
    }

    /**
     * State movements with the time spent in each (§10.2/§10.4).
     *
     * Duration is the gap to the NEXT transition; the current state is still running, so it
     * reports the time since it was entered rather than a closed figure.
     *
     * @return array<int, array<string, mixed>>
     */
    private function transitions(WorkItem $item): array
    {
        $rows = WorkItemTransition::query()
            ->where('work_item_id', $item->id)
            ->with('actor')
            ->orderBy('transitioned_at')
            ->orderBy('id')
            ->get();

        return $rows
            ->map(function (WorkItemTransition $t, int $i) use ($rows) {
                $next = $rows->get($i + 1);
                $ends = $next?->transitioned_at ?? now();
                $minutes = (int) round($t->transitioned_at->diffInSeconds($ends) / 60);

                return [
                    'id' => $t->id,
                    'from' => $t->from_state_name,
                    'to' => $t->to_state_name,
                    'actor' => $this->actor($t->actor),
                    'transitioned_at' => $t->transitioned_at->toIso8601String(),
                    'duration' => WorkItemWorklog::humanDuration(max(0, $minutes)),
                    'is_current' => $next === null,
                ];
            })
            ->reverse() // newest first, like every other tab
            ->values()
            ->all();
    }

    /**
     * The sub-task progress to freeze onto a new update (§8.6). Null when there are no
     * sub-tasks — an update reading "0 / 0" says nothing.
     *
     * @return array{percent:int, completed:int, total:int}|null
     */
    public function progressSnapshot(WorkItem $item): ?array
    {
        $subtasks = WorkItem::query()->where('parent_id', $item->id)->with('state')->get();

        if ($subtasks->isEmpty()) {
            return null;
        }

        $progress = $this->relations->progress($subtasks);

        return $progress['total'] > 0
            ? ['percent' => $progress['percent'], 'completed' => $progress['completed'], 'total' => $progress['total']]
            : null;
    }

    /** @return array<string, mixed>|null */
    private function actor(?User $user): ?array
    {
        return $user ? [
            'id' => $user->id,
            'name' => $user->displayName(),
            'initial' => $user->initial(),
            // The uploaded photo when there is one; the initial is the fallback, not the
            // only option (it used to be, so real avatars never appeared).
            'avatar_url' => $user->avatar_url,
        ] : null;
    }
}
