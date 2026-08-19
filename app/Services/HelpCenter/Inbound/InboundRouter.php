<?php

namespace App\Services\HelpCenter\Inbound;

use App\Models\HelpCenterInbox;

/**
 * Which Inbox an inbound message belongs to (docs/features/help-center.md §8, §20 rules 5 and 8).
 *
 * "Incoming email must be matched to its Inbox using the inbound ID." That is this class, and
 * the reason `inbound_id` is unique across the whole table rather than per tenant: mail arrives
 * carrying a token and nothing else — no workspace, no session — so the token has to identify
 * the Inbox on its own.
 */
class InboundRouter
{
    /**
     * The Inbox addressed by any of these recipients, or null.
     *
     * Deliberately queried WITHOUT the tenant scope: there is no tenancy context when a webhook
     * arrives, and applying one would match nothing. The token IS the lookup, and the Inbox it
     * finds is what establishes which workspace this message belongs to.
     *
     * @param  array<int, string>  $addresses
     */
    public function resolve(array $addresses): ?HelpCenterInbox
    {
        $tokens = [];

        foreach ($addresses as $address) {
            $token = $this->token($address);

            if ($token !== null) {
                $tokens[] = $token;
            }
        }

        if ($tokens === []) {
            return null;
        }

        return HelpCenterInbox::query()
            ->withoutGlobalScopes()
            ->whereIn('inbound_id', $tokens)
            // An archived Inbox has been retired on purpose; mail to it is not routed.
            ->whereNull('archived_at')
            ->first();
    }

    /**
     * The token inside `<prefix>-<token>@<domain>`, if this address is one of ours.
     *
     * The domain must match too. Without that check, `inbox-a8f4k2m9@somebody-else.com` in a Cc
     * would route a stranger's mail into that Inbox — the token is unguessable, but it is also
     * printed in forwarding instructions and pasted into mail clients, so it is not a secret in
     * the way a password is.
     */
    public function token(string $address): ?string
    {
        $address = mb_strtolower(trim($address));

        $prefix = (string) config('help-center.inbound_prefix', 'inbox');
        $domain = mb_strtolower((string) config('help-center.inbound_domain'));

        $pattern = '/^'.preg_quote($prefix, '/').'-([a-z0-9]+)@'.preg_quote($domain, '/').'$/';

        return preg_match($pattern, $address, $matches) ? $matches[1] : null;
    }
}
