<?php

namespace App\Services\HelpCenter\Inbound;

use Illuminate\Support\Carbon;

/**
 * Postmark's inbound webhook body, read into the handful of things ingestion needs
 * (docs/features/help-center.md, P6).
 *
 * A boundary class on purpose. Postmark's JSON is PascalCase, nests recipients as objects, and
 * puts the address we actually care about in a different field depending on how the mail
 * arrived — none of which the rest of the module should have to know. Everything past this
 * point deals in plain arrays and lowercase addresses.
 *
 * Nothing here throws on a missing key: a webhook body is somebody else's data, and the right
 * response to a surprising one is a message with fewer fields, not a 500 that makes Postmark
 * retry it forever.
 */
class PostmarkPayload
{
    /** @param  array<string, mixed>  $data */
    public function __construct(private readonly array $data) {}

    /** @param  array<string, mixed>  $data */
    public static function from(array $data): self
    {
        return new self($data);
    }

    public function fromEmail(): string
    {
        return $this->email($this->data['FromFull']['Email'] ?? $this->data['From'] ?? '');
    }

    public function fromName(): ?string
    {
        $name = trim((string) ($this->data['FromFull']['Name'] ?? ''));

        return $name === '' ? null : $name;
    }

    public function subject(): ?string
    {
        $subject = trim((string) ($this->data['Subject'] ?? ''));

        return $subject === '' ? null : $subject;
    }

    public function textBody(): ?string
    {
        $body = (string) ($this->data['TextBody'] ?? '');

        return trim($body) === '' ? null : $body;
    }

    public function htmlBody(): ?string
    {
        $body = (string) ($this->data['HtmlBody'] ?? '');

        return trim($body) === '' ? null : $body;
    }

    /** The RFC 5322 Message-ID — what makes ingestion idempotent across Postmark's retries. */
    public function messageId(): ?string
    {
        $id = trim((string) ($this->headerValue('Message-ID') ?? $this->data['MessageID'] ?? ''));

        return $id === '' ? null : $id;
    }

    /** Postmark's own id for the delivery, useful when reconciling against their dashboard. */
    public function providerMessageId(): ?string
    {
        $id = trim((string) ($this->data['MessageID'] ?? ''));

        return $id === '' ? null : $id;
    }

    /**
     * What this message is replying to, most recent first.
     *
     * `In-Reply-To` is the direct parent; `References` is the whole chain. Both are checked
     * because clients are inconsistent about which they send, and either is enough to attach a
     * reply to the thread it belongs to instead of opening a second conversation.
     *
     * @return array<int, string>
     */
    public function replyToIds(): array
    {
        $ids = [];

        foreach (['In-Reply-To', 'References'] as $header) {
            preg_match_all('/<[^>]+>/', (string) $this->headerValue($header), $matches);
            $ids = array_merge($ids, $matches[0]);
        }

        return array_values(array_unique(array_reverse($ids)));
    }

    /**
     * The files that came with this email (P66).
     *
     * Postmark base64-encodes each one into the webhook body, so `content` here is the raw
     * bytes — decoded at the boundary, like every other field, so nothing past this point has
     * to know how Postmark spells things.
     *
     * A file whose content will not decode is DROPPED rather than stored empty: a zero-byte row
     * with the right name is worse than no row, because it looks like a file somebody can open.
     *
     * `ContentLength` is Postmark's own count and is deliberately ignored in favour of the
     * decoded length — it is the sender's claim about someone else's data, and the bytes we
     * actually hold are the only size worth recording.
     *
     * @return array<int, array{name: string, mime: string, content: string, content_id: ?string}>
     */
    public function attachments(): array
    {
        $out = [];

        foreach ((array) ($this->data['Attachments'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $content = base64_decode((string) ($entry['Content'] ?? ''), true);

            if ($content === false || $content === '') {
                continue;
            }

            $cid = trim((string) ($entry['ContentID'] ?? ''));

            $out[] = [
                'name' => trim((string) ($entry['Name'] ?? '')),
                'mime' => trim((string) ($entry['ContentType'] ?? '')) ?: 'application/octet-stream',
                'content' => $content,
                'content_id' => $cid === '' ? null : $cid,
            ];
        }

        return $out;
    }

    /** @return array<int, string> */
    public function toRecipients(): array
    {
        return $this->addresses('ToFull');
    }

    /** @return array<int, string> */
    public function ccRecipients(): array
    {
        return $this->addresses('CcFull');
    }

    /**
     * Every address this message could have been routed by, most specific first.
     *
     * `OriginalRecipient` is the envelope recipient and is the ONE that survives forwarding —
     * a customer writes to support@company.com, that mailbox forwards to our inbound address,
     * and the To header still says support@company.com. Postmark reports the real envelope
     * recipient here, which is why it is checked before the headers.
     *
     * Bcc'd inbound addresses appear in neither To nor Cc, and would be unroutable without it.
     *
     * @return array<int, string>
     */
    public function routableAddresses(): array
    {
        $candidates = [];

        $original = $this->email($this->data['OriginalRecipient'] ?? '');

        if ($original !== '') {
            $candidates[] = $original;
        }

        return array_values(array_unique(array_merge(
            $candidates,
            $this->toRecipients(),
            $this->ccRecipients(),
        )));
    }

    /**
     * When the SENDER says they sent it — their `Date:` header (P70).
     *
     * Untrusted, and named so nobody forgets it. It is written by the customer's mail client
     * from the customer's own clock, and it is wrong often enough to matter: the header that
     * prompted this was exactly four hours behind, which made a ticket report that it had been
     * waiting longer than it had existed.
     *
     * Fine for DISPLAY — "they sent this at 1:42 PM" is what they believe — and never used for
     * a clock. `receivedAt()` below is the fact we observed.
     */
    public function sentAt(): Carbon
    {
        $date = (string) ($this->data['Date'] ?? '');

        $parsed = rescue(fn () => Carbon::parse($date), null, report: false);

        if ($parsed === null) {
            return Carbon::now();
        }

        /*
         * Never in the FUTURE.
         *
         * A clock running fast produces a message that sorts above everything sent after it and
         * renders as "in 3 hours". A small tolerance absorbs ordinary skew; beyond that the
         * sender's claim is refused in favour of the moment it reached us.
         */
        return $parsed->isAfter(Carbon::now()->addMinutes(2)) ? Carbon::now() : $parsed;
    }

    /**
     * When WE received it (P70).
     *
     * The moment of ingest, which for an inbound webhook is within seconds of delivery. This is
     * the value every CLOCK uses — the waiting period, the queue ordering, the last-activity
     * stamp — because those measure our own responsiveness and must be built from facts we
     * observed rather than from a header a stranger wrote.
     */
    public function receivedAt(): Carbon
    {
        return Carbon::now();
    }

    /** Postmark's own spam verdict, when the server is configured to run one. */
    public function isSpam(): bool
    {
        foreach ((array) ($this->data['Headers'] ?? []) as $header) {
            if (strcasecmp((string) ($header['Name'] ?? ''), 'X-Spam-Status') === 0) {
                return str_starts_with(strtolower(trim((string) ($header['Value'] ?? ''))), 'yes');
            }
        }

        return false;
    }

    /**
     * Was this sent by a machine that will answer anything we send back?
     *
     * Out-of-office replies, mailing lists, bounce daemons and other autoresponders. We must not
     * acknowledge those: at best the acknowledgement is read by nobody, at worst two automatons
     * introduce themselves to each other repeatedly.
     *
     * The four headers below are the ones that actually appear in the wild. RFC 3834's
     * `Auto-Submitted` is the standard one and is checked first; `Precedence` is the old
     * convention every list still sets; `X-Auto-Response-Suppress` is Microsoft's; a `List-Id`
     * means this came from a list rather than a person.
     */
    public function isAutoSubmitted(): bool
    {
        $autoSubmitted = strtolower(trim((string) $this->headerValue('Auto-Submitted')));

        // "no" is the explicit statement that a human sent it — anything else is a machine.
        if ($autoSubmitted !== '' && $autoSubmitted !== 'no') {
            return true;
        }

        $precedence = strtolower(trim((string) $this->headerValue('Precedence')));

        if (in_array($precedence, ['bulk', 'auto_reply', 'list', 'junk'], true)) {
            return true;
        }

        if (trim((string) $this->headerValue('X-Auto-Response-Suppress')) !== '') {
            return true;
        }

        return trim((string) $this->headerValue('List-Id')) !== '';
    }

    /** @return array<int, string> */
    private function addresses(string $key): array
    {
        $out = [];

        foreach ((array) ($this->data[$key] ?? []) as $entry) {
            $email = $this->email($entry['Email'] ?? '');

            if ($email !== '') {
                $out[] = $email;
            }
        }

        return array_values(array_unique($out));
    }

    private function headerValue(string $name): ?string
    {
        foreach ((array) ($this->data['Headers'] ?? []) as $header) {
            if (strcasecmp((string) ($header['Name'] ?? ''), $name) === 0) {
                return (string) ($header['Value'] ?? '');
            }
        }

        return null;
    }

    private function email(mixed $value): string
    {
        return mb_strtolower(trim((string) $value));
    }
}
