<?php

namespace App\Services\HelpDesk;

use Illuminate\Support\Str;

/**
 * Maps a Postmark inbound webhook onto the payload the ingestor takes
 * (Inbound Email requirements §3, §11).
 *
 * A translation layer and nothing else, deliberately. The ingestor knows about messages, threads
 * and inboxes; it does not know that Postmark capitalizes its keys, puts the delivery recipient
 * in `OriginalRecipient`, and hides In-Reply-To in an array of headers. Keeping that here means
 * the next provider is one more class rather than a second ingestion path — which matters
 * because §3 names Postmark for Phase 1 only.
 *
 * The one mapping that carries weight is `delivered_to`. A forwarded message keeps the CUSTOMER's
 * address in its To header — that is what makes the forwarding visible — while the address it
 * was actually delivered to is the generated one Postmark received it at. Routing needs the
 * second (§11.1); recognising which connected address is working needs the first (§7). Both are
 * carried, separately, because collapsing them loses one of the two answers.
 */
class PostmarkInboundPayload
{
    /**
     * @param  array<string, mixed>  $body  the webhook as Postmark posts it
     * @return array<string, mixed> the normalized message
     */
    public function normalize(array $body): array
    {
        $headers = $this->headers($body);

        return [
            'to' => $this->addresses($body['ToFull'] ?? null, $body['To'] ?? null),
            'cc' => $this->addresses($body['CcFull'] ?? null, $body['Cc'] ?? null),

            /*
             * Where the message was actually delivered — the generated inbound address.
             *
             * `OriginalRecipient` is Postmark's own field for it and is the reliable one. The
             * Delivered-To / X-Original-To headers are read as well because a message that
             * passed through an intermediate forwarder may carry the real destination only
             * there, and a message that routes nowhere is a customer email nobody sees.
             */
            'delivered_to' => array_values(array_unique(array_filter(array_merge(
                [$this->address($body['OriginalRecipient'] ?? null)],
                $this->headerAddresses($headers, ['delivered-to', 'x-original-to', 'x-forwarded-to']),
            )))),

            'from' => [
                'email' => $this->address($body['From'] ?? ($body['FromFull']['Email'] ?? null)),
                'name' => $this->name($body['FromFull'] ?? null),
            ],

            'subject' => (string) ($body['Subject'] ?? ''),
            'text' => $this->nullIfBlank($body['TextBody'] ?? null),
            'html' => $this->nullIfBlank($body['HtmlBody'] ?? null),

            // Threading identifiers (§11.8). Postmark surfaces the Message-ID of the arriving
            // mail as `MessageID` only for its own; the header is the authoritative one.
            'message_id' => $this->header($headers, 'message-id') ?: ($body['MessageID'] ?? null),
            'in_reply_to' => $this->header($headers, 'in-reply-to'),
            'references' => $this->header($headers, 'references'),

            'received_at' => $body['Date'] ?? null,

            'attachments' => $this->attachments($body),
        ];
    }

    /**
     * Postmark's `Attachments`, with the base64 kept as it arrived (§11.6).
     *
     * Decoded once, by whoever stores it, rather than here: this class produces a payload that
     * gets JSON-encoded onto a queue, and decoding early would put raw binary in a job body.
     *
     * @param  array<string, mixed>  $body
     * @return array<int, array<string, mixed>>
     */
    private function attachments(array $body): array
    {
        $attachments = $body['Attachments'] ?? [];

        if (! is_array($attachments)) {
            return [];
        }

        return array_values(array_map(fn ($attachment) => [
            'name' => (string) ($attachment['Name'] ?? 'attachment'),
            'content_type' => $this->nullIfBlank($attachment['ContentType'] ?? null),
            'content' => (string) ($attachment['Content'] ?? ''),
            'size' => (int) ($attachment['ContentLength'] ?? 0),
            // Inline images point at themselves from the body HTML by this.
            'content_id' => $this->nullIfBlank($attachment['ContentID'] ?? null),
        ], array_filter($attachments, 'is_array')));
    }

    /**
     * Postmark's `Headers` array flattened to lowercase name => value.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, string>
     */
    private function headers(array $body): array
    {
        $headers = [];

        foreach ((array) ($body['Headers'] ?? []) as $header) {
            if (is_array($header) && isset($header['Name'])) {
                $headers[strtolower((string) $header['Name'])] = (string) ($header['Value'] ?? '');
            }
        }

        return $headers;
    }

    /** @param array<string, string> $headers */
    private function header(array $headers, string $name): ?string
    {
        return $this->nullIfBlank($headers[$name] ?? null);
    }

    /**
     * The addresses in a set of headers, for the ones that name a single recipient.
     *
     * @param  array<string, string>  $headers
     * @param  array<int, string>  $names
     * @return array<int, string>
     */
    private function headerAddresses(array $headers, array $names): array
    {
        return array_values(array_filter(array_map(
            fn (string $name) => $this->address($headers[$name] ?? null),
            $names,
        )));
    }

    /**
     * A recipient list, preferring Postmark's parsed form over the raw header.
     *
     * The raw header is the fallback rather than the source: "Acme Support <support@acme.com>,
     * dana@example.com" is a parsing job that Postmark has already done, and doing it again
     * badly is how a message gets routed to nobody.
     *
     * @return array<int, string>
     */
    private function addresses(mixed $full, mixed $raw): array
    {
        if (is_array($full) && $full !== []) {
            return array_values(array_filter(array_map(
                fn ($entry) => $this->address(is_array($entry) ? ($entry['Email'] ?? null) : $entry),
                $full,
            )));
        }

        return array_values(array_filter(array_map(
            fn (string $part) => $this->address($part),
            explode(',', (string) ($raw ?? '')),
        )));
    }

    /** One address, lowercased, with any display name stripped. */
    private function address(mixed $value): ?string
    {
        $raw = trim((string) ($value ?? ''));

        if ($raw === '') {
            return null;
        }

        if (preg_match('/<([^<>]+)>/', $raw, $matches)) {
            $raw = $matches[1];
        }

        $address = strtolower(trim($raw));

        // Anything that is not an address routes nowhere and matches nothing, so it is dropped
        // here rather than stored as a recipient that can never be right.
        return Str::contains($address, '@') ? $address : null;
    }

    private function name(mixed $from): ?string
    {
        $name = is_array($from) ? trim((string) ($from['Name'] ?? '')) : '';

        return $name !== '' ? $name : null;
    }

    private function nullIfBlank(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value !== '' ? $value : null;
    }
}
