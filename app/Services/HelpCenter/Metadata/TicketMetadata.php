<?php

namespace App\Services\HelpCenter\Metadata;

use App\Models\HelpCenterCompany;
use App\Services\HelpCenter\Inbound\PostmarkPayload;

/**
 * The source values an incoming Request carries (docs/features/help-center.md, P75 §5).
 *
 * A flat `key => value` map, keyed by `help-center.mapping_sources`. This is the ONE contract
 * between "what arrived" and "what the mappings read": every channel the requirement lists —
 * email, intake form, API, integration, chat, portal — produces one of these, and the engine
 * downstream never learns which one it was.
 *
 * Immutable, and deliberately a value object rather than an array: `custom` sources read a named
 * key out of the caller's own bag, and that lookup needs one place that agrees about
 * case-folding and about what counts as "not provided".
 */
class TicketMetadata
{
    /** @param  array<string, mixed>  $values */
    private function __construct(private readonly array $values) {}

    /** @param  array<string, mixed>  $values */
    public static function make(array $values): self
    {
        $out = [];

        foreach ($values as $key => $value) {
            $key = self::normaliseKey((string) $key);

            if ($key === '') {
                continue;
            }

            // Scalars only. An integration that sends a nested object has sent something no
            // single destination column can hold, and flattening it here would invent a shape.
            if (is_array($value) || is_object($value)) {
                continue;
            }

            $value = trim((string) $value);

            if ($value !== '') {
                $out[$key] = $value;
            }
        }

        return new self($out);
    }

    /**
     * The map an inbound EMAIL produces (P75 §5, email channel).
     *
     * Six of the nine named sources come straight off the message. The three that do not —
     * Sender Phone, External Customer ID, External Company ID — are absent rather than guessed:
     * an email header carries none of them, and inventing a value from the signature block is a
     * parser this product does not have.
     *
     * `x_*` headers ARE read, because that is how an integration forwarding into a Space passes
     * its own identifiers, and reading them costs nothing when they are not there.
     *
     * @param  array<string, mixed>  $extra  Anything the caller already knows — an intake form's
     *                                       fields, an API caller's own bag.
     */
    public static function fromEmail(PostmarkPayload $payload, array $extra = []): self
    {
        $email = $payload->fromEmail();

        return self::make(array_merge([
            'sender_email' => $email,
            'sender_name' => $payload->fromName(),
            'email_domain' => HelpCenterCompany::domainOfEmail($email),
            'ticket_subject' => $payload->subject(),
            'ticket_channel' => 'email',
        ], $extra));
    }

    /**
     * Read one key.
     *
     * Null for "not provided", and an empty string never gets in (see `make`) — the distinction
     * matters because the engine's rule is "a mapping with no value does nothing", and `''`
     * would otherwise clear a field somebody had filled in.
     */
    public function get(string $key): ?string
    {
        $key = self::normaliseKey($key);

        return $key === '' ? null : ($this->values[$key] ?? null);
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /** @return array<string, string> */
    public function all(): array
    {
        return $this->values;
    }

    /** With more values on top — how a channel adds what only it knows. */
    public function with(array $extra): self
    {
        return self::make(array_merge($this->values, $extra));
    }

    /**
     * Case- and separator-insensitive.
     *
     * An integration sending `Order Number`, `order_number` and `order-number` means one field,
     * and a mapping authored against one spelling must not miss the other two.
     */
    private static function normaliseKey(string $key): string
    {
        $key = mb_strtolower(trim($key));

        return (string) preg_replace('/[^a-z0-9]+/', '_', $key);
    }
}
