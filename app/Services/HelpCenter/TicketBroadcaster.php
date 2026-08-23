<?php

namespace App\Services\HelpCenter;

use App\Events\HelpCenterTicketEvent;
use App\Models\HelpCenterMessage;
use App\Models\HelpCenterRequest;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Telling a Space's agents that one of its tickets moved (docs/features/help-center.md, P67).
 *
 * ONE place that composes the payload and decides whether it deserves a toast — the same
 * reasoning `RequestActivity` is one place that records. A screen updating itself is only
 * useful if it updates for every path that changes something, and a broadcast written at each
 * call site is a broadcast that exists for the paths somebody remembered.
 *
 * Nothing here throws. A ticket is not lost because a websocket server is down: every method
 * is a courtesy on top of a page that already works over HTTP, and the client is written to
 * treat the socket as an accelerator rather than as the source of truth.
 *
 * That was the stated contract from the beginning and nothing enforced it — see the try/catch
 * in `fire()` for what it cost.
 */
class TicketBroadcaster
{
    /** A new ticket opened by an inbound email. */
    public function created(HelpCenterRequest $request): void
    {
        $this->fire($request, HelpCenterTicketEvent::CREATED, [
            'title' => 'New ticket received',
            'body' => $this->who($request).' opened '.$request->ticketNumber()
                .' – '.$this->subject($request),
        ]);
    }

    /**
     * A customer answered.
     *
     * The one event the requirement writes out in full, and the only one that always toasts:
     * "New customer reply received / Sarah Johnson replied to #HD-1024 – Unable to access
     * account".
     */
    public function replyReceived(HelpCenterRequest $request, HelpCenterMessage $message): void
    {
        $name = trim((string) ($message->from_name ?: $message->from_email))
            ?: $this->who($request);

        $this->fire($request, HelpCenterTicketEvent::REPLY, [
            'title' => 'New customer reply received',
            'body' => $name.' replied to '.$request->ticketNumber().' – '.$this->subject($request),
        ]);
    }

    /**
     * Something about the ticket changed — status, assignee, priority, tags, a snooze.
     *
     * SILENT: no toast. These are things AGENTS do, and the agent who did it is already looking
     * at the result. A toast for every chip somebody flips on a busy queue is a screen nobody
     * can work in — the row still updates, which is what the requirement asks for.
     *
     * `$field` picks the requirement's more specific name where there is one, so a client that
     * wants to listen for just `ticket.status.changed` can.
     */
    public function updated(HelpCenterRequest $request, ?string $field = null): void
    {
        $type = match ($field) {
            'status' => HelpCenterTicketEvent::STATUS,
            'assignee' => HelpCenterTicketEvent::ASSIGNED,
            default => HelpCenterTicketEvent::UPDATED,
        };

        $this->fire($request, $type);
    }

    /** The queue counts moved — used when nothing about one ticket changed but the totals did. */
    public function unreadChanged(HelpCenterRequest $request): void
    {
        $this->fire($request, HelpCenterTicketEvent::UNREAD);
    }

    /**
     * The payload every event carries.
     *
     * Deliberately NOT the ticket: the client refetches, so ordering, filters and counts stay
     * decided by the server's own queries rather than reimplemented in the browser. What is
     * here is what a toast needs and what a client needs to answer "is this the ticket I have
     * open?" without a round trip.
     *
     * @param  array{title: string, body: string}|null  $toast
     */
    private function fire(HelpCenterRequest $request, string $type, ?array $toast = null): void
    {
        $spaceId = (int) $request->help_center_space_id;
        $tenantId = (string) $request->tenant_id;

        if ($spaceId === 0 || $tenantId === '') {
            return;
        }

        /*
         * The class's "nothing here throws" promise, ACTUALLY kept (P77).
         *
         * `HelpCenterTicketEvent` is `ShouldBroadcastNow` on purpose — a queue worker nobody is
         * running is an update that never arrives — so the HTTP call to Reverb happens inline,
         * inside the agent's request. With Reverb down that call raises `BroadcastException`,
         * and because every write path funnels through `RequestActivity::record()`, that
         * exception came out of the CONTROLLER.
         *
         * Replying was the worst case. The message is stored and the email is already handed to
         * Postmark by the time the activity row is written, so the customer had their answer,
         * the ticket had the message, and the agent was told "Could not send the reply" — an
         * invitation to send it a second time.
         *
         * Caught as `Throwable`, not `BroadcastException`: the failure modes are a refused
         * connection, a DNS failure, a TLS error and a serialization error in the payload, and
         * none of them is a reason to lose an agent's work. Logged at WARNING because it IS
         * worth noticing — a permanently down Reverb means no screen updates itself — but it is
         * not an error in the action the person actually took.
         */
        try {
            HelpCenterTicketEvent::dispatch($tenantId, $spaceId, $type, [
                'id' => (int) $request->id,
                'number' => $request->ticketNumber(),
                'subject' => $this->subject($request),
                'customer' => $this->who($request),
                // So a client can open the ticket straight from a toast without building a URL —
                // and so the route stays the server's business (P46).
                'url' => route('help-center.spaces.requests.page', [
                    'space' => $spaceId,
                    'request' => $request->id,
                ]),
            ], $toast);
        } catch (Throwable $e) {
            Log::warning('help-center.broadcast.failed', [
                'request_id' => (int) $request->id,
                'space_id' => $spaceId,
                'type' => $type,
                // The message only. A stack trace per flipped chip would bury the log on a busy
                // Space, and the message already names the host that could not be reached.
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function subject(HelpCenterRequest $request): string
    {
        return trim((string) $request->subject) ?: '(no subject)';
    }

    private function who(HelpCenterRequest $request): string
    {
        return trim((string) $request->customerLabel()) ?: 'A customer';
    }
}
