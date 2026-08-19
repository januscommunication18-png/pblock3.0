<?php

namespace App\Services\HelpDesk;

use App\Models\HelpDesk;
use App\Models\HelpDeskConversation;
use App\Models\HelpDeskInbox;
use App\Models\HelpDeskMessage;
use App\Services\RichTextSanitizer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Turns an arriving email into a conversation (docs/features/help-desk.md — Phase 2, slice 2:
 * FR-2.4 threading and ingestion, FR-2.7 numbering).
 *
 * The whole of the acceptance criteria for ingestion is here:
 *
 *   "Inbound email creates exactly one conversation when no thread exists" — the thread lookup
 *   below, plus the duplicate check in front of it.
 *   "Replies attach to the correct conversation using thread identifiers" — In-Reply-To first,
 *   then the References chain, both matched against Message-IDs this Help Desk has already seen.
 *
 * IDEMPOTENT by construction. A provider retrying a webhook, a queue retrying a job, or the
 * same message arriving down two paths all end at the same conversation and the same message
 * row (§9: "duplicate inbound email/retry is received", "background job fails and retries").
 * That matters more than it looks: at-least-once delivery is what every mail provider offers,
 * so anything less than idempotent here means duplicate cases in front of an agent.
 */
class InboundEmailIngestor
{
    /** Matches Message-IDs, with or without their angle brackets. */
    private const MESSAGE_ID = '/<([^<>\s]+)>/';

    public function __construct(
        private readonly RichTextSanitizer $sanitizer,
        private readonly InboundAttachmentStore $attachments,
        private readonly HelpDeskEmailAddressManager $addresses,
    ) {}

    /**
     * Ingest one normalized message.
     *
     * @param  array<string, mixed>  $payload
     * @return HelpDeskConversation|null the conversation it landed in, or null if nothing here
     *                                   claims the address it was sent to
     */
    public function ingest(array $payload): ?HelpDeskConversation
    {
        $inbox = $this->resolveInbox($payload);

        if (! $inbox) {
            /*
             * Nothing owns that address. Logged rather than thrown: mail arrives at addresses
             * nobody configured all the time — a decommissioned alias, a typo, a spam run — and
             * raising here would make the queue retry each of them until it gave up.
             */
            Log::info('help_desk.inbound.unrouted', [
                'to' => $this->routingCandidates($payload),
                'message_id' => $this->messageId($payload),
            ]);

            return null;
        }

        $workspace = $inbox->tenant;

        // Everything below is tenant-scoped work, so it runs inside that workspace's context
        // rather than passing tenant ids by hand (CLAUDE.md §7).
        return $workspace->run(fn () => $this->store($inbox, $payload));
    }

    /**
     * Which inbox does the address on the envelope belong to (FR-2.2, §11.1)?
     *
     * Read WITHOUT the tenant scope, because at this point there is no tenant: an arriving
     * message carries an address and nothing else, and the address IS the routing key. That is
     * exactly why `inbound_address` is unique across every workspace — the lookup has to have
     * one answer.
     *
     * `delivered_to` is considered first and matters most. A FORWARDED message — which is the
     * arrangement the requirements are built on (§2) — keeps the customer's own address in its
     * To header and carries the generated address only as the recipient it was delivered to. A
     * lookup that read To alone would fail to route the very case this feature exists for.
     *
     * @param  array<string, mixed>  $payload
     */
    private function resolveInbox(array $payload): ?HelpDeskInbox
    {
        $candidates = $this->routingCandidates($payload);

        if ($candidates === []) {
            return null;
        }

        return HelpDeskInbox::query()
            ->withoutGlobalScopes()
            ->whereIn(DB::raw('lower(inbound_address)'), $candidates)
            ->first();
    }

    /**
     * Every address this message could have been routed by, lowercased.
     *
     * Cc is in here because mail cc'd to a support address is mail sent to support; a customer
     * who copies you on a thread has still written to you.
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, string>
     */
    private function routingCandidates(array $payload): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($address) => strtolower(trim((string) $address)),
            array_merge(
                $this->addressList($payload, 'delivered_to'),
                $this->recipients($payload),
                $this->addressList($payload, 'cc'),
            ),
        ))));
    }

    /** @param  array<string, mixed>  $payload */
    private function store(HelpDeskInbox $inbox, array $payload): HelpDeskConversation
    {
        $helpDesk = $inbox->helpDesk;
        $messageId = $this->messageId($payload);

        /*
         * Seen before? Return where it landed the first time.
         *
         * Checked before anything is written AND enforced by a unique index behind it: this
         * read answers the common case cheaply, and the index is what holds when two copies
         * arrive close enough together to both get past it.
         */
        if ($messageId) {
            $existing = HelpDeskMessage::query()
                ->where('help_desk_id', $helpDesk->id)
                ->where('message_id', $messageId)
                ->first();

            if ($existing) {
                return $existing->conversation;
            }
        }

        $conversation = $this->threadFor($helpDesk, $payload)
            ?? $this->open($helpDesk, $inbox, $payload);

        $sentAt = $this->sentAt($payload);

        try {
            $message = HelpDeskMessage::create([
                'tenant_id' => $helpDesk->tenant_id,
                'help_desk_id' => $helpDesk->id,
                'help_desk_conversation_id' => $conversation->id,
                'direction' => HelpDeskMessage::DIRECTION_INBOUND,
                'message_id' => $messageId,
                'in_reply_to' => $this->firstId((string) ($payload['in_reply_to'] ?? '')),
                'references' => $this->referencesRaw($payload),
                'from_email' => $this->fromEmail($payload),
                'from_name' => $this->fromName($payload),
                'to' => $this->recipients($payload),
                'cc' => $this->addressList($payload, 'cc'),
                'subject' => $this->subject($payload),
                // Sanitized on the way IN, once (§13). Customer mail is the most hostile HTML
                // this application will ever store.
                'body_html' => $this->sanitizer->sanitize((string) ($payload['html'] ?? '')) ?: null,
                'body_text' => ($payload['text'] ?? null) ? (string) $payload['text'] : null,
                'sent_at' => $sentAt,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            // Two copies raced past the read above. The other one won; this is the same message,
            // and its attachments were stored by the copy that got there first.
            return $conversation->fresh();
        }

        // The files the customer sent (§11.6). After the message row, and only on the path that
        // actually created one, so a duplicate delivery cannot store a second copy of every file.
        $this->attachments->store($message, (array) ($payload['attachments'] ?? []));

        /*
         * Somebody's forwarding rule just proved itself (§7).
         *
         * Matched against the addresses the message was SENT to — its To and Cc — because a
         * forwarded message keeps the customer's own address there. That is the only evidence
         * this application can ever have that a rule living in somebody else's mail provider
         * works.
         */
        $this->addresses->recordArrival($inbox, array_merge(
            $this->recipients($payload),
            $this->addressList($payload, 'cc'),
        ));

        $conversation->forceFill(['last_message_at' => $sentAt])->save();

        return $conversation->fresh();
    }

    /**
     * The conversation this message is a reply to, if any (FR-2.4).
     *
     * In-Reply-To first — it names the immediate parent, which is the strongest signal a client
     * gives us. Then the References chain, walked from the most recent backwards, because a
     * client that drops In-Reply-To (or a customer replying from a thread we only partly know)
     * still carries the ancestry there.
     *
     * Subject matching is deliberately NOT a fallback: "Re: Invoice" from two customers is two
     * cases, and a heuristic that merges them is worse than a new conversation somebody moves.
     *
     * @param  array<string, mixed>  $payload
     */
    private function threadFor(HelpDesk $helpDesk, array $payload): ?HelpDeskConversation
    {
        $candidates = array_filter(array_merge(
            [$this->firstId((string) ($payload['in_reply_to'] ?? ''))],
            array_reverse($this->references($payload)),
        ));

        foreach ($candidates as $candidate) {
            $known = HelpDeskMessage::query()
                ->where('help_desk_id', $helpDesk->id)
                ->where('message_id', $candidate)
                ->first();

            if ($known?->conversation) {
                return $known->conversation;
            }
        }

        return null;
    }

    /**
     * Start a conversation, with the next number for this Help Desk (FR-2.7).
     *
     * The counter is incremented under a row lock inside a transaction: two messages arriving in
     * the same instant would otherwise read the same value and both try to claim it, and the
     * unique index on (help_desk_id, number) would turn that into an error on the second one.
     *
     * @param  array<string, mixed>  $payload
     */
    private function open(HelpDesk $helpDesk, HelpDeskInbox $inbox, array $payload): HelpDeskConversation
    {
        return DB::transaction(function () use ($helpDesk, $inbox, $payload) {
            $locked = HelpDesk::query()->whereKey($helpDesk->id)->lockForUpdate()->firstOrFail();
            $number = (int) $locked->conversation_sequence + 1;
            $locked->forceFill(['conversation_sequence' => $number])->save();

            return HelpDeskConversation::create([
                'tenant_id' => $helpDesk->tenant_id,
                'help_desk_id' => $helpDesk->id,
                'help_desk_inbox_id' => $inbox->id,
                'number' => $number,
                'subject' => $this->subject($payload),
                'status' => HelpDeskConversation::STATUS_OPEN,
                'customer_email' => $this->fromEmail($payload),
                'customer_name' => $this->fromName($payload),
                // The inbox's default assignee (FR-2.5). Null is a perfectly good answer: an
                // unassigned conversation is a queue, not a fault.
                'assignee_id' => $inbox->default_assignee_id,
                'last_message_at' => $this->sentAt($payload),
            ]);
        });
    }

    // ---- payload readers ----------------------------------------------------------------

    /** @param  array<string, mixed>  $payload @return array<int, string> */
    private function recipients(array $payload): array
    {
        return $this->addressList($payload, 'to');
    }

    /**
     * One of the payload's address fields, however the provider shaped it.
     *
     * Accepts a bare string, a list of strings and a list of `{email, name}` — three forms
     * because three providers send three, and normalizing here is cheaper than a mapper per
     * field.
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, string>
     */
    private function addressList(array $payload, string $key): array
    {
        $value = $payload[$key] ?? [];

        return array_values(array_filter(array_map(
            fn ($address) => trim((string) (is_array($address) ? ($address['email'] ?? '') : $address)),
            is_array($value) ? $value : [$value],
        )));
    }

    /** @param  array<string, mixed>  $payload */
    private function fromEmail(array $payload): string
    {
        $from = $payload['from'] ?? '';

        return strtolower(trim((string) (is_array($from) ? ($from['email'] ?? '') : $from)));
    }

    /** @param  array<string, mixed>  $payload */
    private function fromName(array $payload): ?string
    {
        $from = $payload['from'] ?? [];
        $name = is_array($from) ? trim((string) ($from['name'] ?? '')) : '';

        return $name !== '' ? $name : null;
    }

    /** @param  array<string, mixed>  $payload */
    private function subject(array $payload): ?string
    {
        $subject = trim((string) ($payload['subject'] ?? ''));

        // Stored at its column's length rather than rejected: a 400-character subject line is
        // unusual, not invalid, and refusing the message would lose a customer's email over it.
        return $subject !== '' ? Str::limit($subject, 250, '') : null;
    }

    /** @param  array<string, mixed>  $payload */
    private function messageId(array $payload): ?string
    {
        return $this->firstId((string) ($payload['message_id'] ?? ''));
    }

    /** @param  array<string, mixed>  $payload @return array<int, string> */
    private function references(array $payload): array
    {
        $references = $payload['references'] ?? [];
        $raw = is_array($references) ? implode(' ', $references) : (string) $references;

        preg_match_all(self::MESSAGE_ID, $raw, $matches);

        // A chain that arrived without angle brackets is still a chain.
        return $matches[1] ?: array_values(array_filter(preg_split('/\s+/', trim($raw)) ?: []));
    }

    /** @param  array<string, mixed>  $payload */
    private function referencesRaw(array $payload): ?string
    {
        $references = $payload['references'] ?? null;
        $raw = is_array($references) ? implode(' ', $references) : (string) $references;

        return trim($raw) !== '' ? trim($raw) : null;
    }

    /**
     * One Message-ID, without its angle brackets and inside the column's length.
     *
     * Trimmed to 190 characters because that is what the indexed column holds — a longer
     * Message-ID is pathological, and truncating consistently keeps dedupe and threading
     * agreeing with each other, which is the only property that matters here.
     */
    private function firstId(string $raw): ?string
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        $id = preg_match(self::MESSAGE_ID, $raw, $m) ? $m[1] : $raw;

        return Str::limit($id, 190, '');
    }

    /** @param  array<string, mixed>  $payload */
    private function sentAt(array $payload): Carbon
    {
        $raw = (string) ($payload['received_at'] ?? $payload['date'] ?? '');

        // A provider's timestamp is a nice-to-have; the moment we received it is the fact we
        // can always state, so an unparseable date falls back to now rather than failing.
        return rescue(fn () => $raw !== '' ? Carbon::parse($raw) : now(), now(), report: false);
    }
}
