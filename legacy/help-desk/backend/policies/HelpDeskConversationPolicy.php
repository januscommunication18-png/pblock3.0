<?php

namespace App\Policies;

use App\Models\HelpDeskConversation;
use App\Models\HelpDeskInbox;
use App\Models\User;
use App\Services\HelpDesk\HelpDeskAccess;

/**
 * Who may do what to a conversation (docs/features/help-desk.md — Phase 2, FR-2.6/2.8).
 *
 * Phase 2 authorizes two things: seeing a conversation at all, and moving it. Replying, noting
 * and assigning arrive with the conversation workspace in Phase 3 and belong here too.
 */
class HelpDeskConversationPolicy
{
    public function __construct(private readonly HelpDeskAccess $access) {}

    /**
     * May they see it?
     *
     * Inbox access, and nothing else — a conversation is only ever as visible as the inbox it
     * sits in (§11: "unauthorized members cannot view inbox content").
     */
    public function view(User $user, HelpDeskConversation $conversation): bool
    {
        return $this->access->canOpenInbox(
            $user,
            $conversation->tenant,
            (int) $conversation->help_desk_inbox_id,
        );
    }

    /**
     * May they move it into that inbox (FR-2.8)?
     *
     * Three conditions, and the first two are the acceptance criterion's word "authorized"
     * taken seriously at BOTH ends:
     *
     *   1. they can open the inbox it is in — otherwise moving it is a way to touch something
     *      they cannot see;
     *   2. they can open the inbox it is going to — otherwise moving it is a way to hide it
     *      somewhere they will not have to look at it;
     *   3. their Help Desk role carries `move`. Agents have it, because misrouted mail is
     *      something the person reading it fixes. Collaborators and Viewers do not: one is
     *      internal-only and the other is read-only, and filing is neither.
     */
    public function move(User $user, HelpDeskConversation $conversation, HelpDeskInbox $target): bool
    {
        $workspace = $conversation->tenant;

        return $this->access->canOpenInbox($user, $workspace, (int) $conversation->help_desk_inbox_id)
            && $this->access->canOpenInbox($user, $workspace, (int) $target->id)
            && $this->access->allows($user, $workspace, 'move');
    }
}
