<?php

namespace App\Services\HelpCenter;

use App\Models\HelpCenterEmailAddress;
use App\Models\Workspace;

/**
 * §7's uniqueness rule, in one place (docs/features/help-center.md §7, HC-D6).
 *
 * Three callers ask this question — the wizard's Add button, the wizard's Continue, and the
 * Inboxes screen's own Add — and §7 gives one answer with one wording. Three copies of it would
 * be three chances for the message to drift, and for one path to be stricter than another.
 */
class EmailAddressGuard
{
    /** §7's exact wording. */
    public const TAKEN_MESSAGE = 'This email address is already connected to another Inbox.';

    /**
     * Could mail actually be forwarded from this address?
     *
     * `email:rfc` accepts `support@localhost` and `support@company` — legal addresses on a
     * local network, and useless here. These are PUBLIC addresses a company already publishes
     * and customers already write to (§10), so the domain has to be one the internet can
     * resolve. Without this the address is accepted, the forwarding is set up against something
     * that can never deliver, and the failure surfaces as "the Help Center does not work".
     *
     * A dot with something after it, rather than a DNS lookup: a validator that makes a network
     * call is a validator that fails when the network does, and MX records are checked properly
     * at delivery time, not on a form.
     */
    public static function isRoutable(string $email): bool
    {
        $email = mb_strtolower(trim($email));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $domain = substr(strrchr($email, '@') ?: '', 1);

        return (bool) preg_match('/^[^.\s]+(\.[^.\s]+)*\.[a-z]{2,}$/', $domain);
    }

    /**
     * Is this address already routing somewhere in this workspace?
     *
     * Scoped to the tenant, never global: two companies may each publish their own support@,
     * and a check that stopped the second would be one workspace's configuration constraining
     * another's.
     *
     * `withoutGlobalScopes()` with an explicit `tenant_id` rather than the model's scope,
     * because this is also asked from paths that have a workspace in hand but no initialized
     * tenancy context — the explicit `where` IS the isolation (CLAUDE.md §7).
     */
    public function isTaken(Workspace $workspace, string $email, ?int $ignoreId = null): bool
    {
        $email = mb_strtolower(trim($email));

        if ($email === '') {
            return false;
        }

        return HelpCenterEmailAddress::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $workspace->id)
            ->whereRaw('lower(email) = ?', [$email])
            ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists();
    }
}
