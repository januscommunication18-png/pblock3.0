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

    public function receivedAt(): Carbon
    {
        $date = (string) ($this->data['Date'] ?? '');

        // A malformed Date header is the sender's problem, not a reason to refuse the message.
        return rescue(fn () => Carbon::parse($date), Carbon::now(), report: false);
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
