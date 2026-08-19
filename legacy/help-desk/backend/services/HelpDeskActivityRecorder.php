<?php

namespace App\Services\HelpDesk;

use App\Models\HelpDesk;
use App\Models\HelpDeskActivity;
use App\Models\HelpDeskConversation;
use App\Models\HelpDeskEmailAddress;
use App\Models\HelpDeskInbox;
use App\Models\HelpDeskMember;
use App\Models\HelpDeskSpace;
use App\Models\User;

/**
 * Writes the Help Desk activity stream (FR-1.9, §8: "record significant state changes").
 *
 * Every entry stores the LABEL it should read with, resolved now — the person's name, the
 * inbox's name, the role as it was called. Storing only ids would make the stream a live view
 * of the present: renaming an inbox would silently rewrite the entry that recorded its
 * creation, and an entry about somebody removed from the workspace would read as "—".
 *
 * One recorder rather than a call to `HelpDeskActivity::create()` at each site, so the
 * vocabulary of the stream is decided in one file and the callers say what happened rather than
 * how it is stored.
 */
class HelpDeskActivityRecorder
{
    public function memberAdded(HelpDesk $helpDesk, ?User $actor, HelpDeskMember $member): void
    {
        $this->write($helpDesk, $actor, HelpDeskActivity::EVENT_MEMBER_ADDED, $this->nameOf($member), [
            'role' => $member->role,
            'role_label' => HelpDeskMember::label($member->role),
            'user_id' => $member->user_id,
        ]);
    }

    public function roleChanged(HelpDesk $helpDesk, ?User $actor, HelpDeskMember $member, string $from): void
    {
        $this->write($helpDesk, $actor, HelpDeskActivity::EVENT_MEMBER_ROLE_CHANGED, $this->nameOf($member), [
            'from' => $from,
            'from_label' => HelpDeskMember::label($from),
            'to' => $member->role,
            'to_label' => HelpDeskMember::label($member->role),
            'user_id' => $member->user_id,
        ]);
    }

    public function statusChanged(HelpDesk $helpDesk, ?User $actor, HelpDeskMember $member): void
    {
        $this->write($helpDesk, $actor, HelpDeskActivity::EVENT_MEMBER_STATUS_CHANGED, $this->nameOf($member), [
            'status' => $member->status,
            'user_id' => $member->user_id,
        ]);
    }

    /** @param  array<int, string>  $inboxNames */
    public function inboxesChanged(HelpDesk $helpDesk, ?User $actor, HelpDeskMember $member, array $inboxNames): void
    {
        $this->write($helpDesk, $actor, HelpDeskActivity::EVENT_MEMBER_INBOXES_CHANGED, $this->nameOf($member), [
            'inboxes' => $inboxNames,
            'user_id' => $member->user_id,
        ]);
    }

    public function memberRemoved(HelpDesk $helpDesk, ?User $actor, HelpDeskMember $member): void
    {
        $this->write($helpDesk, $actor, HelpDeskActivity::EVENT_MEMBER_REMOVED, $this->nameOf($member), [
            'role' => $member->role,
            'user_id' => $member->user_id,
        ]);
    }

    public function invited(HelpDesk $helpDesk, ?User $actor, string $email, string $role): void
    {
        $this->write($helpDesk, $actor, HelpDeskActivity::EVENT_MEMBER_INVITED, $email, [
            'role' => $role,
            'role_label' => HelpDeskMember::label($role),
        ]);
    }

    public function inviteRevoked(HelpDesk $helpDesk, ?User $actor, string $email): void
    {
        $this->write($helpDesk, $actor, HelpDeskActivity::EVENT_INVITE_REVOKED, $email);
    }

    /** Recorded with no actor: the person accepting is the subject, not the administrator. */
    public function inviteAccepted(HelpDesk $helpDesk, User $joiner, string $role): void
    {
        $this->write($helpDesk, $joiner, HelpDeskActivity::EVENT_INVITE_ACCEPTED, $joiner->displayName(), [
            'role' => $role,
            'role_label' => HelpDeskMember::label($role),
            'user_id' => $joiner->id,
        ]);
    }

    public function inboxCreated(HelpDesk $helpDesk, ?User $actor, HelpDeskInbox $inbox): void
    {
        $this->write($helpDesk, $actor, HelpDeskActivity::EVENT_INBOX_CREATED, $inbox->name, [
            'inbox_id' => $inbox->id,
        ]);
    }

    public function inboxRenamed(HelpDesk $helpDesk, ?User $actor, HelpDeskInbox $inbox, string $from): void
    {
        $this->write($helpDesk, $actor, HelpDeskActivity::EVENT_INBOX_RENAMED, $inbox->name, [
            'from' => $from,
            'inbox_id' => $inbox->id,
        ]);
    }

    /**
     * An inbox was given a new inbound address (Inbound Email requirements §2).
     *
     * Recorded with BOTH addresses, because this is the one setting change that silently breaks
     * something outside this application: every forwarding rule pointing at the old address
     * stops working, and the only way to answer "why did mail stop arriving in March?" later is
     * to have written down what it used to be.
     */
    public function inboundAddressRegenerated(HelpDesk $helpDesk, ?User $actor, HelpDeskInbox $inbox, string $from): void
    {
        $this->write($helpDesk, $actor, HelpDeskActivity::EVENT_INBOX_ADDRESS_REGENERATED, $inbox->name, [
            'from' => $from,
            'to' => $inbox->inbound_address,
            'inbox_id' => $inbox->id,
        ]);
    }

    /** A customer-facing address was connected to an inbox (§9). */
    public function emailAddressAdded(HelpDesk $helpDesk, ?User $actor, HelpDeskEmailAddress $emailAddress, HelpDeskInbox $inbox): void
    {
        $this->write($helpDesk, $actor, HelpDeskActivity::EVENT_EMAIL_ADDRESS_ADDED, $emailAddress->address, [
            'inbox' => $inbox->name,
            'inbox_id' => $inbox->id,
            'email_address_id' => $emailAddress->id,
        ]);
    }

    /**
     * Forwarded mail actually arrived from that address for the first time (§7).
     *
     * NO ACTOR, like a delivery failure: nobody here did this. A customer's mail provider
     * forwarded a message and that is what proved the connection works.
     */
    public function emailAddressConnected(HelpDesk $helpDesk, HelpDeskEmailAddress $emailAddress, HelpDeskInbox $inbox): void
    {
        $this->write($helpDesk, null, HelpDeskActivity::EVENT_EMAIL_ADDRESS_CONNECTED, $emailAddress->address, [
            'inbox' => $inbox->name,
            'inbox_id' => $inbox->id,
            'email_address_id' => $emailAddress->id,
        ]);
    }

    public function emailAddressStatusChanged(HelpDesk $helpDesk, ?User $actor, HelpDeskEmailAddress $emailAddress): void
    {
        $this->write($helpDesk, $actor, HelpDeskActivity::EVENT_EMAIL_ADDRESS_STATUS_CHANGED, $emailAddress->address, [
            'status' => $emailAddress->status,
            'email_address_id' => $emailAddress->id,
        ]);
    }

    /** The address itself, not the row: the row is gone and the stream outlives it. */
    public function emailAddressRemoved(HelpDesk $helpDesk, ?User $actor, string $address): void
    {
        $this->write($helpDesk, $actor, HelpDeskActivity::EVENT_EMAIL_ADDRESS_REMOVED, $address);
    }

    // ---- spaces and inbox assignment (Workspace & Inbox Assignment requirements) -------------

    public function spaceCreated(HelpDesk $helpDesk, ?User $actor, HelpDeskSpace $space): void
    {
        $this->write($helpDesk, $actor, HelpDeskActivity::EVENT_SPACE_CREATED, $space->name, [
            'space_id' => $space->id,
            'types' => $space->typeLabels(),
        ]);
    }

    public function spaceRenamed(HelpDesk $helpDesk, ?User $actor, HelpDeskSpace $space, string $from): void
    {
        $this->write($helpDesk, $actor, HelpDeskActivity::EVENT_SPACE_RENAMED, $space->name, [
            'from' => $from,
            'space_id' => $space->id,
        ]);
    }

    public function spaceArchived(HelpDesk $helpDesk, ?User $actor, HelpDeskSpace $space): void
    {
        $this->write($helpDesk, $actor, HelpDeskActivity::EVENT_SPACE_ARCHIVED, $space->name, [
            'space_id' => $space->id,
        ]);
    }

    public function spaceRestored(HelpDesk $helpDesk, ?User $actor, HelpDeskSpace $space): void
    {
        $this->write($helpDesk, $actor, HelpDeskActivity::EVENT_SPACE_RESTORED, $space->name, [
            'space_id' => $space->id,
        ]);
    }

    /**
     * An inbox moved between spaces (§7).
     *
     * Both ends are stored as NAMES resolved now, like every other entry here: a space renamed
     * next year must not rewrite what this move said at the time. Either end may be null — an
     * inbox can be filed for the first time, or taken out of the structure.
     */
    public function inboxAssigned(HelpDesk $helpDesk, ?User $actor, HelpDeskInbox $inbox, ?string $from, ?string $to): void
    {
        $this->write($helpDesk, $actor, HelpDeskActivity::EVENT_INBOX_ASSIGNED, $inbox->name, [
            'from' => $from,
            'to' => $to,
            'inbox_id' => $inbox->id,
            'space_id' => $inbox->help_desk_space_id,
        ]);
    }

    public function setupCompleted(HelpDesk $helpDesk, ?User $actor): void
    {
        $this->write($helpDesk, $actor, HelpDeskActivity::EVENT_SETUP_COMPLETED, $helpDesk->name);
    }

    /** A conversation was refiled into another inbox (FR-2.8). */
    public function conversationMoved(
        HelpDesk $helpDesk,
        ?User $actor,
        HelpDeskConversation $conversation,
        string $from,
        string $to,
    ): void {
        $this->write($helpDesk, $actor, HelpDeskActivity::EVENT_CONVERSATION_MOVED, $conversation->reference(), [
            'from' => $from,
            'to' => $to,
            'conversation_id' => $conversation->id,
        ]);
    }

    /**
     * A reply did not reach the customer (FR-2.9).
     *
     * Recorded with NO actor: nobody here did this, a mail server did. The stream renders an
     * actorless entry as "Someone", which is why the sentence for this event reads as a
     * statement about the message rather than as something a person performed.
     */
    public function deliveryFailed(
        HelpDesk $helpDesk,
        HelpDeskConversation $conversation,
        string $recipient,
        ?string $reason,
    ): void {
        $this->write($helpDesk, null, HelpDeskActivity::EVENT_DELIVERY_FAILED, $conversation->reference(), [
            'recipient' => $recipient,
            'reason' => $reason,
            'conversation_id' => $conversation->id,
        ]);
    }

    /** @param  array<string, mixed>  $meta */
    private function write(HelpDesk $helpDesk, ?User $actor, string $event, ?string $subject = null, array $meta = []): void
    {
        HelpDeskActivity::create([
            'tenant_id' => $helpDesk->tenant_id,
            'help_desk_id' => $helpDesk->id,
            'actor_id' => $actor?->id,
            'event' => $event,
            'subject' => $subject,
            'meta' => $meta === [] ? null : $meta,
        ]);
    }

    private function nameOf(HelpDeskMember $member): string
    {
        // Loaded rather than assumed: a member row reached through a route binding has no user.
        return (string) ($member->user()->first()?->displayName() ?? $member->user_id);
    }
}
