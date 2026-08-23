<?php

namespace App\Services\HelpCenter;

use App\Models\HelpCenterInbox;
use App\Models\HelpCenterRequest;
use App\Models\HelpCenterSpace;

/**
 * Who a Space's outgoing mail is FROM (docs/features/help-center.md, P62).
 *
 * One place that answers it, because the requirement's central rule is that the answer comes from
 * the TICKET'S SPACE and nothing else — not the agent, not the workspace owner, not the
 * application's global sender, and not another Space. Resolved at send time from the ticket, so a
 * Billing reply and a Technical Support reply cannot end up sharing an address just because the
 * same person sent them.
 *
 * ## The address is the Space's INBOUND one (P64)
 *
 * From and Reply-To are both `inbox-<id>@inbound.<domain>` — the address this Space's mail
 * already arrives at. That is the requirement's critical routing rule, and it is the one address
 * guaranteed to come back to us: a customer replying to it reaches a mailbox we own and route,
 * with no forwarding rule in between that somebody might switch off.
 *
 * P62 used the customer-facing address (`support@theircompany.com`) instead, on the reasoning
 * that it is the branded one. It is — and it is also an address the CUSTOMER'S SUPPLIER owns.
 * A reply to it lands in their mailbox and only reaches us if their forwarding rule is still in
 * place, which makes the round trip depend on somebody else's configuration.
 *
 * ## "Verified" means two different things (P63, kept because it is still a trap)
 *
 * `HelpCenterEmailAddress::STATUS_VERIFIED` is set by `InboundIngestor::markAddressesVerified()`
 * when mail ARRIVES at an address — "the forwarding rule works". It is NOT a provider sender
 * signature, which this application neither sets nor can see. Gating sending on it once blocked
 * replies for five of six Spaces. Nothing here reads it any more.
 *
 * ## The display name is the Space's, and configurable (P65)
 *
 * `from_name` comes from `HelpCenterSpace::senderName()` — the Space's Inbound Email Display
 * Name, or its own name when nobody has set one. It is carried on the REPLY-TO as well as the
 * From, because the requirement states both headers as one identity: `eBay Support
 * <inbox-wknbatec@inbound.myprojectblock.dev>`.
 */
class SpaceSender
{
    /**
     * The sender identity for a ticket, or the reason there is not one.
     *
     * @return array{
     *     ok: bool, error: ?string,
     *     from_email: ?string, from_name: ?string, reply_to: ?string, inbound: ?string
     * }
     */
    public function for(HelpCenterRequest $request): array
    {
        return $this->forSpace($request->space, $request->inbox);
    }

    /**
     * The same identity, resolved from the SPACE rather than from a ticket (P65).
     *
     * The requirement's §4 is that every customer-facing email from a Space carries this sender —
     * the reply, the new-ticket confirmation, the auto-response, the rating request, the status
     * notification. Several of those are sent by code that has a Space and an Inbox but no
     * Request, or no Request yet, so the Space is the honest parameter and `for()` above is the
     * convenience for the ticket case.
     *
     * @return array{
     *     ok: bool, error: ?string,
     *     from_email: ?string, from_name: ?string,
     *     reply_to: ?string, reply_to_name: ?string, inbound: ?string
     * }
     */
    public function forSpace(?HelpCenterSpace $space, ?HelpCenterInbox $inbox = null): array
    {
        $inbox ??= $space?->inboxes->first();

        if ($space === null || $inbox === null) {
            return $this->refuse();
        }

        /*
         * The INBOUND address, which every Inbox has by construction — it is generated when the
         * Inbox is created, not configured by anybody. So the only way to get here without one
         * is to have no Inbox at all, which the guard above already covers.
         *
         * Nothing is read from `emailAddresses` any more. Those are the addresses CUSTOMERS
         * write to, which is a different question from what we send as (P64).
         */
        $inbound = $inbox->inboundAddress();

        /*
         * The Space's configured display name, falling back to its own name (P65).
         *
         * Still never the agent's. "eBay Support <inbox-…@inbound…>" is what the customer sees;
         * a reply signed by the team rather than by an individual also lets the next message
         * come from somebody else without the customer noticing a change of person — the agent's
         * own name is in the signature (P48), which is where it belongs.
         */
        $name = $space->senderName();

        return [
            'ok' => true,
            'error' => null,
            'from_email' => $inbound,
            'from_name' => $name,
            /*
             * Reply-To is the SAME address AND the same name, exactly as the requirement's
             * critical rule states — `From:` and `Reply-To:` are one identity, so a client that
             * shows the Reply-To (and several do, on a reply) shows the same team.
             *
             * A ticket-tagged variant (`inbox-…+r6-1fac444890@…`, P62) would identify the ticket
             * as well as the Space, and the machinery for it is still here and still the first
             * thing the ingestor tries. It is not used on the way out because the requirement is
             * explicit that both headers carry the plain address, and it pairs that with
             * threading headers to do the ticket identification. Switching it back on is one
             * line — see `replyTo()` — if threading ever proves unreliable in the field.
             */
            'reply_to' => $inbound,
            'reply_to_name' => $name,
            'inbound' => $inbound,
        ];
    }

    /** `inbox-<id>+r<ticket>-<sig>@<domain>` — see HelpCenterRequest::replyTag(). */
    public function replyTo($inbox, HelpCenterRequest $request): string
    {
        $address = $inbox->inboundAddress();
        [$local, $domain] = explode('@', $address, 2);

        return $local.'+'.$request->replyTag().'@'.$domain;
    }

    /**
     * The one refusal message, worded as the requirement words it.
     *
     * The same message for "no Inbox" and "no address": they are one problem from the agent's
     * side — this Space has no support address yet — and they are fixed in the same place.
     *
     * "Not verified" is deliberately NOT one of them any more (P63).
     *
     * @return array{
     *     ok: bool, error: string, from_email: null, from_name: null,
     *     reply_to: null, reply_to_name: null, inbound: null
     * }
     */
    private function refuse(): array
    {
        return [
            'ok' => false,
            'error' => 'Unable to send reply. Configure the support email address for this Space '
                .'under Settings → Channel.',
            'from_email' => null,
            'from_name' => null,
            'reply_to' => null,
            'reply_to_name' => null,
            'inbound' => null,
        ];
    }
}
