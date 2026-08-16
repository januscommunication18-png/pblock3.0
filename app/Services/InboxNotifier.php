<?php

namespace App\Services;

use App\Events\InboxNotificationCreated;
use App\Models\InboxNotification;
use App\Models\Mention;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkItemComment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Everything that decides when an Inbox entry appears and when it goes away
 * (docs/features/inbox.md §4, §19, §28-§30).
 *
 * One class rather than a line in each caller, because the rules that matter here are the
 * NEGATIVE ones, and those are the ones that get forgotten when they live at call sites:
 *
 *  §4.1 — never a second unread entry for the same assignment.
 *  §15  — never for something you did to yourself.
 *  §28  — unassigning removes the unread entry it created; there is no value in asking
 *         somebody to review an assignment that no longer exists.
 *  §29  — reassigning is those two rules together: the old assignee's unread entry goes, the
 *         new one gets theirs.
 *  §19  — deleting a comment removes the unread mentions that pointed into it.
 *
 * Read entries are never touched by any of this. Once somebody has seen a notification, it is
 * a thing that happened to them; §29 says so explicitly.
 */
class InboxNotifier
{
    public function __construct(private readonly RichTextSanitizer $richText) {}

    /**
     * Somebody was given a work item (§4.1).
     *
     * Only ever called with the NEWLY added assignees — the diff is the caller's, because it
     * is the caller that knows what changed.
     */
    public function assigned(WorkItem $item, User $assignee, ?User $actor): void
    {
        // §15's sibling: assigning something to yourself needs no notification.
        if ($actor && (int) $actor->id === (int) $assignee->id) {
            return;
        }

        // §4.1: "Duplicate Inbox entries should be prevented." An unread entry for this item
        // already says the thing this one would say.
        $exists = InboxNotification::query()
            ->for($assignee->id)
            ->unread()
            ->where('type', InboxNotification::TYPE_ASSIGNMENT)
            ->where('work_item_id', $item->id)
            ->exists();

        if ($exists) {
            return;
        }

        $item->loadMissing('project');

        $this->create([
            'recipient_id' => $assignee->id,
            'actor_id' => $actor?->id,
            'type' => InboxNotification::TYPE_ASSIGNMENT,
            'project_id' => $item->project_id,
            'work_item_id' => $item->id,
            'source_type' => Mention::SOURCE_WORK_ITEM,
            'source_id' => $item->id,
            // §42: enough to draw the row without loading the work item behind it — which now
            // means the description as well as the title. "Configure Stale Ticket Automation"
            // tells you what it is called; the first line of the description tells you whether
            // it is yours to worry about today.
            'title' => $item->title,
            'excerpt' => $this->richText->excerpt($item->description, (int) config('projects.excerpt.inbox')),
        ]);
    }

    /**
     * Somebody was named (§7, §11, §20).
     *
     * @param  Collection<int, User>  $users  newly mentioned, from MentionSync::sync()
     */
    public function mentioned(
        Collection $users,
        WorkItem $item,
        ?User $actor,
        string $sourceType,
        ?string $html,
        ?WorkItemComment $comment = null,
    ): void {
        $item->loadMissing('project');
        // §12: the line the row shows, frozen now — a later edit of the comment should not
        // rewrite a notification somebody has already been sent.
        $excerpt = $this->richText->excerpt($html, (int) config('projects.excerpt.inbox'));

        foreach ($users as $user) {
            // §15: mentioning yourself creates the mention record but never the notification.
            if ($actor && (int) $user->id === (int) $actor->id) {
                continue;
            }

            // §17: one notification per person per source, however many times the text names
            // them. MentionSync has already deduplicated, but a re-save must not add a second.
            $exists = InboxNotification::query()
                ->for($user->id)
                ->unread()
                ->where('type', InboxNotification::TYPE_MENTION)
                ->where('source_type', $sourceType)
                ->where('source_id', $comment?->id ?? $item->id)
                ->exists();

            if ($exists) {
                continue;
            }

            $this->create([
                'recipient_id' => $user->id,
                'actor_id' => $actor?->id,
                'type' => InboxNotification::TYPE_MENTION,
                'project_id' => $item->project_id,
                'work_item_id' => $item->id,
                'comment_id' => $comment?->id,
                'source_type' => $sourceType,
                'source_id' => $comment?->id ?? $item->id,
                'title' => $item->title,
                'excerpt' => $excerpt,
            ]);
        }
    }

    /**
     * Write the row, then say so on the wire (§27).
     *
     * Every creation path goes through here so the broadcast cannot be forgotten by whichever
     * notification type §33 adds next — the alternative is a `dispatch()` beside each
     * `create()`, and the one that gets missed is the one nobody notices.
     *
     * Sent AFTER the transaction commits: a rolled-back assignment must not light up somebody
     * else's Inbox with a row that no longer exists.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function create(array $attributes): void
    {
        /** @var InboxNotification $notification */
        $notification = InboxNotification::create($attributes);

        DB::afterCommit(function () use ($notification) {
            try {
                InboxNotificationCreated::dispatch(
                    (string) $notification->tenant_id,
                    (int) $notification->recipient_id,
                    $this->card($notification),
                    $this->counts((int) $notification->recipient_id),
                );
            } catch (\Throwable $e) {
                // The row is written and the Inbox will show it on the next load. A websocket
                // server being down must not fail the edit that triggered this.
                Log::warning('inbox.broadcast_failed', [
                    'notification_id' => $notification->id,
                    'reason' => $e->getMessage(),
                ]);
            }
        });
    }

    /**
     * The unread counts, for the badge the event carries (§26).
     *
     * @return array<string, int>
     */
    private function counts(int $userId): array
    {
        $byType = InboxNotification::query()
            ->for($userId)->unread()
            ->selectRaw('type, count(*) as total')->groupBy('type')
            ->pluck('total', 'type');

        $assignment = (int) ($byType[InboxNotification::TYPE_ASSIGNMENT] ?? 0);
        $mention = (int) ($byType[InboxNotification::TYPE_MENTION] ?? 0);

        return ['assignment' => $assignment, 'mention' => $mention, 'all' => $assignment + $mention];
    }

    /**
     * The row the socket carries — the same shape the list endpoint returns, so a row that
     * arrived live and one that arrived on load are the same object to the client.
     *
     * @return array<string, mixed>
     */
    private function card(InboxNotification $n): array
    {
        $n->loadMissing(['actor', 'project:id,name', 'workItem:id,identifier,title,project_id']);

        return [
            'id' => $n->id,
            'type' => $n->type,
            'title' => $n->workItem?->title ?? $n->title,
            'identifier' => $n->workItem?->identifier,
            'excerpt' => $n->excerpt,
            'created_at' => $n->created_at?->toIso8601String(),
            'actor' => $n->actor ? [
                'id' => $n->actor->id,
                'name' => $n->actor->displayName(),
                'initial' => $n->actor->initial(),
                'avatar_url' => $n->actor->avatar_url,
                'avatar_color' => $n->actor->avatarColor(),
            ] : null,
            'project' => $n->project ? ['id' => $n->project->id, 'name' => $n->project->name] : null,
            'work_item_id' => $n->work_item_id,
            'project_id' => $n->project_id,
            'comment_id' => $n->comment_id,
            // Freshly written for this person, so it is theirs to read by construction.
            'readable' => true,
        ];
    }

    /**
     * Somebody stopped being an assignee (§28/§29).
     *
     * Unread only. A notification already read is a record of something that happened, and
     * §29 is explicit that no action is required against it.
     *
     * @param  array<int, int>  $userIds
     */
    public function unassigned(WorkItem $item, array $userIds): void
    {
        if ($userIds === []) {
            return;
        }

        InboxNotification::query()
            ->unread()
            ->where('type', InboxNotification::TYPE_ASSIGNMENT)
            ->where('work_item_id', $item->id)
            ->whereIn('recipient_id', $userIds)
            ->delete();
    }

    /**
     * A comment was deleted (§19).
     *
     * Comments are SOFT deleted, so no foreign key cascade fires — this is the only thing that
     * clears the notifications pointing into one. Unread only, for the same reason as above.
     */
    public function commentDeleted(WorkItemComment $comment): void
    {
        InboxNotification::query()
            ->unread()
            ->where('comment_id', $comment->id)
            ->delete();
    }
}
