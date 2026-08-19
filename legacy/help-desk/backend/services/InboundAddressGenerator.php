<?php

namespace App\Services\HelpDesk;

use App\Models\HelpDeskInbox;
use Illuminate\Support\Str;

/**
 * Generates an inbox's inbound address (Inbound Email requirements §2; Setup Inbox flow, step 3).
 *
 * The address is GENERATED and read-only, and both halves of that matter:
 *
 *   generated — because it has to be unique across every workspace for routing to have one
 *               answer, and asking administrators to pick a unique string on a domain they do
 *               not own is asking them to fail;
 *   read-only — because somebody's mail provider is forwarding to it. Editing it silently
 *               breaks a forwarding rule in a system this application cannot see. Changing it
 *               is possible, but only as an explicit act with a warning attached (§2's
 *               "regenerated only through an explicit administrative action").
 *
 * The shape is `inbox-{unique-id}@{domain}`, and it carries NOTHING derived from the inbox, the
 * space or the tenant — which is the Setup flow's rule, stated three ways: the inbox name must
 * not appear in it, renaming must not change it, and moving the inbox between spaces must not
 * change it. All three are the same requirement, and a name-derived address would break the
 * first two the moment somebody edited a field. An address people publish should not carry a
 * workspace's identifiers around with it either.
 *
 * The id is stored beside the address (`help_desk_inboxes.inbound_id`) rather than parsed back
 * out of it, because the address is a routing key and the id is the fact it was built from —
 * the Setup screen shows them as separate lines for exactly that reason.
 */
class InboundAddressGenerator
{
    /** The prefix every generated address carries, always (Setup Inbox flow, step 3). */
    public const PREFIX = 'inbox-';

    /** How long the unguessable half is. Six base-36 characters is ~2 billion possibilities. */
    private const ID_LENGTH = 6;

    /**
     * A fresh id and the address built from it, in use nowhere.
     *
     * Uniqueness is checked against every workspace deliberately (`withoutGlobalScopes`): this
     * is the routing key, and the database's unique index is the backstop behind this loop.
     *
     * @return array{id: string, address: string}
     */
    public function generate(?int $ignoreInboxId = null): array
    {
        $domain = (string) config('help-desk.inbound.domain', 'inbound.projectblock.app');

        do {
            $id = Str::lower(Str::random(self::ID_LENGTH));
            $address = self::PREFIX.$id.'@'.$domain;
        } while ($this->taken($address, $ignoreInboxId));

        return ['id' => $id, 'address' => $address];
    }

    /**
     * Give an inbox an address, replacing whatever it had.
     *
     * The same act at both ends of an inbox's life, deliberately one method: at creation it is
     * how an inbox gets the address §2 says it must have, and afterwards it is §2's "regenerated
     * only through an explicit administrative action". A second method would be a second place
     * the shape of an address is decided.
     *
     * Regenerating is destructive in a way nothing else on the inbox screen is: every forwarding
     * rule pointing at the old address stops working the moment this is saved, in a mail
     * provider this application cannot see. That is why it is a button with a warning rather
     * than a field somebody can edit by accident — and why `forceFill` is right here even though
     * the column is deliberately absent from `$fillable`.
     */
    public function assign(HelpDeskInbox $inbox): HelpDeskInbox
    {
        $generated = $this->generate($inbox->id);

        $inbox->forceFill([
            'inbound_id' => $generated['id'],
            'inbound_address' => $generated['address'],
        ])->save();

        return $inbox;
    }

    private function taken(string $address, ?int $ignoreInboxId): bool
    {
        return HelpDeskInbox::query()
            ->withoutGlobalScopes()
            ->where('inbound_address', $address)
            ->when($ignoreInboxId, fn ($q) => $q->whereKeyNot($ignoreInboxId))
            ->exists();
    }
}
