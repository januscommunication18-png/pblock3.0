<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Something new needs somebody's attention (inbox §27).
 *
 * `ShouldBroadcastNow`, not `ShouldBroadcast`: the same decision every other notification in
 * this application runs under — delivery is immediate rather than queued, because an alert
 * waiting for a worker that nobody is running never arrives at all. See WorkItemStatusNotifier
 * for the trade that buys.
 *
 * The payload is the COUNTS and a small card, never the work item. The Inbox list is meant to
 * be drawable without loading what it points at (§42), and a socket message is the worst place
 * to start joining tables — one assignment to a busy project would fan out a full work item to
 * every open tab.
 *
 * Broadcast on the tenant-scoped user channel (§12): a person is in several workspaces, and
 * what they should hear about depends on the one they are looking at.
 */
class InboxNotificationCreated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<string, mixed>  $card
     * @param  array<string, int>  $counts
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly int $recipientId,
        public readonly array $card,
        public readonly array $counts,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("tenant.{$this->tenantId}.user.{$this->recipientId}")];
    }

    public function broadcastAs(): string
    {
        return 'inbox.created';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['item' => $this->card, 'counts' => $this->counts];
    }
}
