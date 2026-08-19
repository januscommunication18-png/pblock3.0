<?php

namespace App\Services\HelpDesk;

use App\Models\HelpDeskConversation;
use App\Models\HelpDeskInbox;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moving a conversation between inboxes (docs/features/help-desk.md — Phase 2, slice 3: FR-2.8).
 *
 * The acceptance criterion is "agents can move a conversation between AUTHORIZED inboxes", and
 * the word doing the work there is "authorized" — both ends of the move are checked, not just
 * the destination. Moving a conversation out of an inbox you cannot open would be a way to read
 * what is in it; moving one into an inbox you cannot open would be a way to hide it.
 *
 * What deliberately does NOT change on a move:
 *   - the conversation's NUMBER. It is how people refer to a case out loud, and renumbering it
 *     because somebody filed it differently would break every reference to it;
 *   - its messages, its customer, its history.
 *
 * What does change is the assignee, and only when it has to: see below.
 */
class ConversationRouter
{
    public function __construct(
        private readonly HelpDeskAccess $access,
        private readonly HelpDeskActivityRecorder $activity,
    ) {}

    /**
     * Move `$conversation` into `$target`.
     *
     * Authorization is the caller's to have checked (the policy does it against both inboxes);
     * this is where the domain rules live.
     */
    public function move(HelpDeskConversation $conversation, HelpDeskInbox $target, User $actor): HelpDeskConversation
    {
        if ((int) $target->help_desk_id !== (int) $conversation->help_desk_id) {
            // Not reachable through the screen — the target list is built from this Help Desk —
            // so this is the crafted-request case, refused rather than trusted.
            throw ValidationException::withMessages([
                'inbox_id' => 'That inbox belongs to another Help Desk.',
            ]);
        }

        if ((int) $target->id === (int) $conversation->help_desk_inbox_id) {
            // Already there. Silent success rather than an error: a double-click is not a
            // mistake worth a message, and it must not write a second activity entry.
            return $conversation;
        }

        $from = $conversation->inbox;

        return DB::transaction(function () use ($conversation, $target, $actor, $from) {
            $conversation->forceFill([
                'help_desk_inbox_id' => $target->id,
                'assignee_id' => $this->assigneeAfterMove($conversation, $target),
            ])->save();

            $this->activity->conversationMoved(
                $conversation->helpDesk,
                $actor,
                $conversation->fresh(),
                (string) ($from?->name ?? '—'),
                (string) $target->name,
            );

            return $conversation->fresh();
        });
    }

    /**
     * Who owns the conversation once it has moved.
     *
     * Three rules, in order:
     *
     *   1. an assignee who can still reach the conversation in its new home KEEPS it. A move is
     *      a filing decision, and taking a case away from the person handling it because it was
     *      refiled is how work gets dropped;
     *   2. an assignee who CANNOT reach the new inbox is cleared — leaving them assigned would
     *      be an assignment they can neither see nor act on, which §9's "user loses access while
     *      a conversation is open" is the same problem from the other side;
     *   3. an unassigned conversation picks up the destination inbox's default assignee, because
     *      that is what arriving there means (FR-2.5).
     */
    private function assigneeAfterMove(HelpDeskConversation $conversation, HelpDeskInbox $target): ?int
    {
        $assignee = $conversation->assignee;

        if (! $assignee) {
            return $target->default_assignee_id;
        }

        $user = $assignee->user;

        $keeps = $user !== null
            && $assignee->isActive()
            && $this->access->canOpenInbox($user, $target->tenant, $target->id);

        return $keeps ? $assignee->id : null;
    }
}
