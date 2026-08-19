<?php

namespace App\Services\HelpDesk;

use App\Models\HelpDeskConversation;
use App\Models\HelpDeskEmailDelivery;
use App\Models\HelpDeskMember;
use App\Models\HelpDeskMessage;
use App\Models\User;
use App\Notifications\HelpDeskDeliveryFailed;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Records what the mail provider says happened to a reply (docs/features/help-desk.md —
 * Phase 2, slice 3: FR-2.9).
 *
 * The acceptance criterion is "failed outbound delivery creates a visible delivery event and
 * NOTIFICATION", and both halves matter for the same reason: a bounced reply is silent. Without
 * the event nothing on any screen says the customer never heard; without the notification
 * nobody looks at the screen.
 *
 * Idempotent, like ingestion: providers retry their webhooks, and a retried bounce must not
 * raise a second alarm about the same failure (§8's "notifications must be deduplicated", §9's
 * retry case).
 */
class EmailDeliveryRecorder
{
    public function __construct(private readonly HelpDeskActivityRecorder $activity) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return HelpDeskEmailDelivery|null null when nothing here sent the message it is about
     */
    public function record(array $payload): ?HelpDeskEmailDelivery
    {
        $message = $this->resolveMessage($payload);

        if (! $message) {
            /*
             * We did not send this. Logged rather than thrown: providers report on everything
             * that leaves an account, including mail this application knows nothing about, and
             * raising here would make the queue retry each of them.
             */
            Log::info('help_desk.delivery.unrouted', [
                'message_id' => $this->messageId($payload),
                'status' => $this->status($payload),
            ]);

            return null;
        }

        $workspace = $message->tenant;

        return $workspace->run(fn () => $this->store($message, $payload));
    }

    /** @param  array<string, mixed>  $payload */
    private function store(HelpDeskMessage $message, array $payload): ?HelpDeskEmailDelivery
    {
        $eventId = $this->eventId($payload);

        // Seen this exact event before? Then everything below already happened for it.
        if ($eventId) {
            $seen = HelpDeskEmailDelivery::query()
                ->where('help_desk_id', $message->help_desk_id)
                ->where('event_id', $eventId)
                ->first();

            if ($seen) {
                return $seen;
            }
        }

        $conversation = $message->conversation;
        $status = $this->status($payload);
        $recipient = $this->recipient($payload, $message);
        $reason = $this->reason($payload);

        $delivery = HelpDeskEmailDelivery::create([
            'tenant_id' => $message->tenant_id,
            'help_desk_id' => $message->help_desk_id,
            'help_desk_conversation_id' => $conversation->id,
            'help_desk_message_id' => $message->id,
            'status' => $status,
            'recipient' => $recipient,
            'reason' => $reason,
            'event_id' => $eventId,
            'occurred_at' => $this->occurredAt($payload),
        ]);

        if ($delivery->isFailure()) {
            $this->raise($conversation, $delivery);
        } elseif ($delivery->isDelivered()) {
            $this->clear($conversation);
        }

        return $delivery;
    }

    /**
     * Make the failure visible, and tell somebody — once.
     *
     * The flag is what makes it visible in every list; the transition from "not failing" to
     * "failing" is what makes the notification a single alarm rather than one per bounce. A
     * conversation that is already known to be broken does not need telling again: the person
     * who was told is the person who has to fix it.
     */
    private function raise(HelpDeskConversation $conversation, HelpDeskEmailDelivery $delivery): void
    {
        $alreadyKnown = $conversation->delivery_failed_at !== null;

        $conversation->forceFill(['delivery_failed_at' => $delivery->occurred_at ?? now()])->save();

        $this->activity->deliveryFailed(
            $conversation->helpDesk,
            $conversation,
            $delivery->recipient,
            $delivery->reason,
        );

        if ($alreadyKnown) {
            return;
        }

        $recipients = $this->notifiable($conversation);

        if ($recipients === []) {
            return;
        }

        Notification::send($recipients, new HelpDeskDeliveryFailed(
            $conversation,
            $delivery->recipient,
            $delivery->reason,
            (string) ($conversation->helpDesk->tenant?->name ?? 'your workspace'),
        ));
    }

    /** A later success means the conversation is no longer broken. */
    private function clear(HelpDeskConversation $conversation): void
    {
        if ($conversation->delivery_failed_at !== null) {
            $conversation->forceFill(['delivery_failed_at' => null])->save();
        }
    }

    /**
     * Who hears about it — "send notifications only to authorized recipients" (§8).
     *
     * The assignee, because it is their reply that did not arrive. Failing that, the Help Desk's
     * admins, because an unassigned conversation with a bounced reply belongs to whoever runs
     * the desk and to nobody else. Deliberately NOT everybody with access to the inbox: a bounce
     * is not news to five people, and a notification everybody receives is one nobody acts on.
     *
     * @return array<int, User>
     */
    private function notifiable(HelpDeskConversation $conversation): array
    {
        $assignee = $conversation->assignee;

        if ($assignee?->isActive() && $assignee->user) {
            return [$assignee->user];
        }

        return HelpDeskMember::query()
            ->where('help_desk_id', $conversation->help_desk_id)
            ->where('role', HelpDeskMember::ROLE_ADMIN)
            ->active()
            ->with('user')
            ->get()
            ->map(fn (HelpDeskMember $member) => $member->user)
            ->filter()
            ->values()
            ->all();
    }

    /**
     * The message this event is about.
     *
     * Looked up WITHOUT the tenant scope and by Message-ID, because a provider webhook arrives
     * outside any workspace — the same position the inbound endpoint is in. The id is one we
     * generated when the reply was sent, so it identifies exactly one message.
     *
     * @param  array<string, mixed>  $payload
     */
    private function resolveMessage(array $payload): ?HelpDeskMessage
    {
        $messageId = $this->messageId($payload);

        if (! $messageId) {
            return null;
        }

        return HelpDeskMessage::query()
            ->withoutGlobalScopes()
            ->where('message_id', $messageId)
            ->where('direction', HelpDeskMessage::DIRECTION_OUTBOUND)
            ->first();
    }

    /** @param  array<string, mixed>  $payload */
    private function messageId(array $payload): ?string
    {
        $raw = trim((string) ($payload['message_id'] ?? ''));

        if ($raw === '') {
            return null;
        }

        $id = preg_match('/<([^<>\s]+)>/', $raw, $m) ? $m[1] : $raw;

        // Trimmed the same way ingestion trims, so the two agree about what an id is.
        return Str::limit($id, 190, '');
    }

    /** @param  array<string, mixed>  $payload */
    private function status(array $payload): string
    {
        $status = strtolower(trim((string) ($payload['status'] ?? ($payload['event'] ?? ''))));

        // An unknown status from a provider is recorded as a failure rather than discarded:
        // whatever it was, it was not "delivered", and the wrong side to err on here is silence.
        return in_array($status, HelpDeskEmailDelivery::STATUSES, true)
            ? $status
            : HelpDeskEmailDelivery::STATUS_FAILED;
    }

    /** @param  array<string, mixed>  $payload */
    private function recipient(array $payload, HelpDeskMessage $message): string
    {
        $recipient = trim((string) ($payload['recipient'] ?? ''));

        if ($recipient !== '') {
            return $recipient;
        }

        // Fall back to who the message was addressed to — an event with no recipient is still
        // about somebody, and "—" on a screen helps nobody.
        $to = (array) ($message->to ?? []);

        return (string) ($to[0] ?? $message->conversation?->customer_email ?? '');
    }

    /** @param  array<string, mixed>  $payload */
    private function reason(array $payload): ?string
    {
        $reason = trim((string) ($payload['reason'] ?? $payload['description'] ?? ''));

        return $reason !== '' ? Str::limit($reason, 500) : null;
    }

    /** @param  array<string, mixed>  $payload */
    private function eventId(array $payload): ?string
    {
        $id = trim((string) ($payload['event_id'] ?? ''));

        return $id !== '' ? Str::limit($id, 190, '') : null;
    }

    /** @param  array<string, mixed>  $payload */
    private function occurredAt(array $payload): Carbon
    {
        $raw = (string) ($payload['occurred_at'] ?? $payload['timestamp'] ?? '');

        return rescue(fn () => $raw !== '' ? Carbon::parse($raw) : now(), now(), report: false);
    }
}
