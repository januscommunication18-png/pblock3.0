<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Something happened to a ticket, told to everyone watching its Space
 * (docs/features/help-center.md, P67).
 *
 * ## One class, several event names
 *
 * The requirement lists six — `ticket.created`, `ticket.updated`, `ticket.reply.received`,
 * `ticket.assigned`, `ticket.status.changed`, `ticket.unread.updated`. They are one fact
 * ("this ticket moved") with six labels, and six classes would be six copies of the same
 * channel, the same payload and the same tenancy rule, free to drift the first time one of them
 * is changed. `broadcastAs()` returns the type, so the wire looks exactly as the requirement
 * describes while the rules live in one place.
 *
 * ## The channel is the SPACE, not the person
 *
 * `private-tenant.{tenantId}.help-center.space.{spaceId}` (CLAUDE.md §12). A new customer reply
 * concerns whoever is looking at that Space's Inbox, which is usually several people and not
 * necessarily the assignee — a per-user channel would leave the agent who is actually reading
 * the queue the last to know.
 *
 * ## `ShouldBroadcastNow` and `ShouldDispatchAfterCommit`
 *
 * **Now**, because a queue worker nobody is running is an update that never arrives — the same
 * decision `InboxNotificationCreated` runs under.
 *
 * **After commit**, because this fires from inside the ingest transaction. Without it the socket
 * message would beat its own COMMIT: the browser would be told "there is a new reply", refetch
 * immediately, and be served the state from before the write. That is not a rare race — it is
 * the ordinary case on a fast connection.
 *
 * ## The payload is small on purpose
 *
 * Enough to identify the ticket and to write a toast, never the ticket itself. The client
 * refetches what it needs, so ordering, filters and counts stay decided by the server's own
 * queries rather than reimplemented in the browser — and one busy Space does not fan a full
 * conversation out to every open tab.
 */
class HelpCenterTicketEvent implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public const CREATED = 'ticket.created';

    public const UPDATED = 'ticket.updated';

    public const REPLY = 'ticket.reply.received';

    public const ASSIGNED = 'ticket.assigned';

    public const STATUS = 'ticket.status.changed';

    public const UNREAD = 'ticket.unread.updated';

    /** @param  array<string, mixed>  $ticket */
    public function __construct(
        public readonly string $tenantId,
        public readonly int $spaceId,
        public readonly string $type,
        public readonly array $ticket,
        /*
         * The toast, composed on the SERVER or not at all.
         *
         * Null means "update quietly" — a status change somebody else made does not deserve an
         * interruption, a customer reply does. Deciding it here keeps one answer to "is this
         * worth a notification", rather than each screen guessing from the type.
         */
        public readonly ?array $toast = null,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("tenant.{$this->tenantId}.help-center.space.{$this->spaceId}")];
    }

    public function broadcastAs(): string
    {
        return $this->type;
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'type' => $this->type,
            'space_id' => $this->spaceId,
            'ticket' => $this->ticket,
            'toast' => $this->toast,
        ];
    }
}
