<?php

namespace App\Services\HelpCenter\Inbound;

use App\Models\HelpCenterCustomer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/**
 * Matching an inbound sender to a customer (docs/features/help-center.md, P33).
 *
 * "If a matching customer exists, link the ticket to that customer. If no customer exists,
 * create or identify the sender as a new customer record."
 *
 * Matched on the EXTERNAL CUSTOMER ID first, then on the EMAIL — the requirement's own priority
 * order (P75 §6). The id is only ever present when an integration or an intake form put it
 * there, and when it is, it is a stronger claim about identity than an address: one person may
 * write from two addresses, and a shared mailbox is two people behind one.
 *
 * The email is what makes this work for ordinary mail. It is the only thing an inbound message
 * reliably carries about its sender — names are absent, spelled three ways, or the mail client's
 * idea of one — and it is what a reply is addressed to. Matching on a NAME is deliberately not
 * done at all: it would merge two John Smiths and split one person who signs off differently on
 * their phone.
 */
class CustomerMatcher
{
    /**
     * The customer for this sender, created if this is the first time they have written.
     *
     * Returns null for a message with no usable address: a Request from one still renders,
     * because the Request keeps its own copy of whatever the message said.
     */
    public function match(
        string $tenantId,
        ?string $email,
        ?string $name,
        ?Carbon $receivedAt = null,
        ?string $externalId = null,
    ): ?HelpCenterCustomer {
        $email = mb_strtolower(trim((string) $email));
        $externalId = trim((string) $externalId) ?: null;

        $usableEmail = $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL);

        /*
         * An external id with no usable address is still an identity.
         *
         * An intake form or an API caller can name the customer without an address at all, and
         * refusing those would mean a Request with no Customer for a channel that knew exactly
         * who it was. An address is still required to CREATE, because a customer record with no
         * address is one no reply can reach and no later email can match.
         */
        if (! $usableEmail && $externalId === null) {
            return null;
        }

        $customer = $this->find($tenantId, $usableEmail ? $email : null, $externalId);

        if ($customer === null) {
            return $usableEmail
                ? $this->create($tenantId, $email, $name, $externalId, $receivedAt)
                : null;
        }

        /*
         * An existing record LEARNS a name; it never loses one.
         *
         * Somebody's first email may come from a client that sends no display name, and their
         * second may carry it. Filling the gap is useful. Overwriting is not — a name somebody
         * typed into the record by hand must not be replaced by whatever a mail header said this
         * morning, and there is no way to tell the two apart after the fact.
         */
        $fill = [];

        if (trim((string) $customer->name) === '' && trim((string) $name) !== '') {
            $fill['name'] = $name;
        }

        // A backfilled record, or one created before this ran, may have no first contact.
        if ($customer->first_contact_at === null) {
            $fill['first_contact_at'] = $receivedAt ?? now();
        }

        // The id an integration supplied, learned the same way a name is: filled when the
        // record has none, never replaced.
        if ($externalId !== null && trim((string) $customer->external_id) === '') {
            $fill['external_id'] = $externalId;
        }

        if ($fill !== []) {
            $customer->forceFill($fill)->save();
        }

        return $customer;
    }

    /** External id first, then email — the requirement's priority order (P75 §6). */
    private function find(string $tenantId, ?string $email, ?string $externalId): ?HelpCenterCustomer
    {
        $base = fn (): Builder => HelpCenterCustomer::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId);

        if ($externalId !== null) {
            $found = $base()->where('external_id', $externalId)->first();

            if ($found !== null) {
                return $found;
            }
        }

        return $email === null ? null : $base()->where('email', $email)->first();
    }

    /**
     * Create, and survive losing the race.
     *
     * Two messages from one new sender can be ingested by two workers at the same moment: both
     * find nothing and both insert. The unique index on `(tenant_id, email)` makes the loser's
     * insert fail, and the right answer for the loser is the row the winner just wrote — not an
     * exception that fails an ingest and makes Postmark retry the message forever.
     */
    private function create(
        string $tenantId,
        string $email,
        ?string $name,
        ?string $externalId,
        ?Carbon $receivedAt,
    ): ?HelpCenterCustomer {
        try {
            return HelpCenterCustomer::create([
                'tenant_id' => $tenantId,
                'email' => $email,
                'name' => $name,
                'external_id' => $externalId,
                'first_contact_at' => $receivedAt ?? now(),
                'last_activity_at' => $receivedAt ?? now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->find($tenantId, $email, $externalId);
        }
    }
}
