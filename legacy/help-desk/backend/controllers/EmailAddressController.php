<?php

namespace App\Http\Controllers\HelpDesk;

use App\Http\Requests\HelpDesk\StoreEmailAddressRequest;
use App\Models\HelpDesk;
use App\Models\HelpDeskEmailAddress;
use App\Models\HelpDeskInbox;
use App\Services\HelpDesk\HelpDeskEmailAddressManager;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Help Desk › Settings › Inboxes › Email Addresses
 * (Inbound Email requirements §4 navigation, §5 the page, §6 forwarding, §7 status,
 * §8 verification, §9 saving, §10 the list).
 *
 * Two screens with URLs of their own rather than one screen with a modal, which is §4's whole
 * point: connecting an address is a task somebody leaves and comes back to — they have to go and
 * configure a forwarding rule in another system in the middle of it — and a modal is a thing you
 * cannot come back to. A URL survives a refresh, a bookmark, the Back button, and being pasted
 * to whoever actually administers the customer's mail.
 *
 * Everything here is administration, so every action re-asks `guardAdminister` (§13: hiding the
 * control is not authorization), and re-checks that the inbox and the address in the URL belong
 * to the Help Desk the request is in.
 */
class EmailAddressController extends AreaController
{
    /** GET /help-desk/settings/inboxes/{inbox}/email-addresses — §10 */
    public function index(HelpDeskInbox $inbox): View
    {
        $helpDesk = $this->helpDesk();
        $this->guardAdminister($helpDesk);
        $this->guardInbox($helpDesk, $inbox);

        return $this->page('settings.email-addresses', [
            'inbox' => $this->inboxPayload($inbox),
            'addresses' => $this->addressRows($inbox),
            'endpoints' => [
                'create' => route('help-desk.inboxes.addresses.create', $inbox),
                'address' => route('help-desk.inboxes.addresses.update', ['inbox' => $inbox->id, 'emailAddress' => '__ID__']),
                'verify' => route('help-desk.inboxes.addresses.verify', ['inbox' => $inbox->id, 'emailAddress' => '__ID__']),
            ],
        ], ['helpDesk' => $helpDesk, 'inboxModel' => $inbox]);
    }

    /**
     * GET /help-desk/settings/inboxes/{inbox}/email-addresses/new — §4, §5
     *
     * A page, not a modal. The inbox in the URL is the one preselected (§5), and the picker
     * still offers the others: somebody who arrives here from a bookmark, or who realises
     * halfway through that billing@ belongs somewhere else, should not have to start again.
     */
    public function create(HelpDeskInbox $inbox): View
    {
        $helpDesk = $this->helpDesk();
        $this->guardAdminister($helpDesk);
        $this->guardInbox($helpDesk, $inbox);

        return $this->page('settings.email-address-new', [
            'inbox_id' => $inbox->id,
            // Every inbox with its generated address, so switching the picker updates the
            // forwarding instructions without another round trip.
            'inboxes' => $this->inboxOptions($helpDesk),
            'endpoints' => [
                'store' => route('help-desk.inboxes.addresses.store', ['inbox' => '__ID__']),
                'cancel' => route('help-desk.inboxes.addresses', $inbox),
            ],
        ], ['helpDesk' => $helpDesk, 'inboxModel' => $inbox]);
    }

    /**
     * POST /help-desk/settings/inboxes/{inbox}/email-addresses — §9
     *
     * Answers with where to go rather than redirecting itself: the page posts with `fetch`, like
     * every other screen in this area, so the browser has to be told. The success notification
     * is flashed HERE so it survives that navigation and is shown by the list page — the same
     * thing a server-side redirect would do, and for the same reason.
     */
    public function store(StoreEmailAddressRequest $request, HelpDeskInbox $inbox, HelpDeskEmailAddressManager $addresses): JsonResponse
    {
        $helpDesk = $this->helpDesk();
        $this->guardAdminister($helpDesk);
        $this->guardInbox($helpDesk, $inbox);

        // The picker may have moved it to another inbox of this Help Desk (§5). Checked against
        // the Help Desk rather than trusted: an id is the easiest thing in a request to change.
        $target = $this->targetInbox($helpDesk, $inbox, $request->validated('help_desk_inbox_id'));

        $addresses->connect($helpDesk, $target, (string) $request->validated('address'), $this->user());

        session()->flash('status', 'Email address added successfully. Configure forwarding to start receiving messages in this Inbox.');

        return response()->json([
            'ok' => true,
            'redirect' => route('help-desk.inboxes.addresses', $target),
        ]);
    }

    /**
     * POST /help-desk/settings/inboxes/{inbox}/email-addresses/{emailAddress}/verify — §8
     *
     * Starts watching; it does not send anything. See HelpDeskEmailAddressManager::verify — the
     * test message has to travel through the customer's own mail provider, so the only thing
     * that can send it is a person writing to their support address.
     */
    public function verify(HelpDeskInbox $inbox, HelpDeskEmailAddress $emailAddress, HelpDeskEmailAddressManager $addresses): JsonResponse
    {
        $helpDesk = $this->helpDesk();
        $this->guardAdminister($helpDesk);
        $this->guardInbox($helpDesk, $inbox);
        $this->guardAddress($inbox, $emailAddress);

        $addresses->verify($emailAddress);

        return $this->listResponse($inbox);
    }

    /** PATCH /help-desk/settings/inboxes/{inbox}/email-addresses/{emailAddress} — §10 Disable */
    public function update(
        Request $request,
        HelpDeskInbox $inbox,
        HelpDeskEmailAddress $emailAddress,
        HelpDeskEmailAddressManager $addresses,
    ): JsonResponse {
        $helpDesk = $this->helpDesk();
        $this->guardAdminister($helpDesk);
        $this->guardInbox($helpDesk, $inbox);
        $this->guardAddress($inbox, $emailAddress);

        $addresses->setDisabled($helpDesk, $emailAddress, $request->boolean('disabled'), $this->user());

        return $this->listResponse($inbox);
    }

    /** DELETE /help-desk/settings/inboxes/{inbox}/email-addresses/{emailAddress} — §10 Delete */
    public function destroy(HelpDeskInbox $inbox, HelpDeskEmailAddress $emailAddress, HelpDeskEmailAddressManager $addresses): JsonResponse
    {
        $helpDesk = $this->helpDesk();
        $this->guardAdminister($helpDesk);
        $this->guardInbox($helpDesk, $inbox);
        $this->guardAddress($inbox, $emailAddress);

        $addresses->remove($helpDesk, $emailAddress, $this->user());

        return $this->listResponse($inbox);
    }

    // ---- shared -------------------------------------------------------------------------

    /**
     * The inbox this list belongs to, with what the forwarding instructions need (§6).
     *
     * @return array<string, mixed>
     */
    private function inboxPayload(HelpDeskInbox $inbox): array
    {
        return [
            'id' => $inbox->id,
            'name' => $inbox->name,
            'inbound_address' => $inbox->inbound_address,
            'instructions' => $inbox->forwardingInstructions(),
        ];
    }

    /**
     * Every inbox in this Help Desk, for the picker on the new-address page (§5).
     *
     * @return array<int, array<string, mixed>>
     */
    private function inboxOptions(HelpDesk $helpDesk): array
    {
        return HelpDeskInbox::query()
            ->where('help_desk_id', $helpDesk->id)
            ->orderBy('name')
            ->get()
            ->map(fn (HelpDeskInbox $i) => [
                'id' => $i->id,
                'name' => $i->name,
                'inbound_address' => $i->inbound_address,
                'instructions' => $i->forwardingInstructions(),
            ])
            ->all();
    }

    /**
     * The connected addresses as §10's table reads them.
     *
     * `markErrorIfVerificationLapsed` runs per row on the way out: a verification that never
     * resolved becomes an error when somebody looks, rather than when a scheduled job happens to
     * run. "Waiting for email" three days later is not waiting, it is broken.
     *
     * @return array<int, array<string, mixed>>
     */
    private function addressRows(HelpDeskInbox $inbox): array
    {
        return HelpDeskEmailAddress::query()
            ->where('help_desk_inbox_id', $inbox->id)
            ->with('creator')
            ->orderBy('address')
            ->get()
            ->each(fn (HelpDeskEmailAddress $a) => $a->markErrorIfVerificationLapsed())
            ->map(fn (HelpDeskEmailAddress $a) => [
                'id' => $a->id,
                'address' => $a->address,
                'inbound_address' => $inbox->inbound_address,
                'status' => $a->status,
                'status_label' => $a->statusLabel(),
                'status_hint' => $a->statusHint(),
                'disabled' => $a->isDisabled(),
                'connected' => $a->isConnected(),
                'verified_at' => optional($a->verified_at)->format('M d, Y \a\t H:i'),
                'last_email_at' => optional($a->last_email_at)->diffForHumans(),
                'added_by' => $a->creator?->displayName(),
                'instructions' => $inbox->forwardingInstructions($a->address),
            ])
            ->all();
    }

    private function listResponse(HelpDeskInbox $inbox): JsonResponse
    {
        return response()->json(['ok' => true, 'addresses' => $this->addressRows($inbox->fresh())]);
    }

    /**
     * Which inbox the address is being connected to.
     *
     * Falls back to the one in the URL when the picker sent something this Help Desk does not
     * own — the same treatment InboxController gives a stale default assignee, and for the same
     * reason: it is a stale screen or a crafted id, and neither is worth losing the form over.
     */
    private function targetInbox(HelpDesk $helpDesk, HelpDeskInbox $inbox, mixed $requested): HelpDeskInbox
    {
        if (! $requested || (int) $requested === (int) $inbox->id) {
            return $inbox;
        }

        return HelpDeskInbox::query()
            ->where('help_desk_id', $helpDesk->id)
            ->whereKey((int) $requested)
            ->first() ?? $inbox;
    }

    /** 404 rather than 403: another Help Desk's inbox is not a permission problem, it is not here. */
    private function guardInbox(HelpDesk $helpDesk, HelpDeskInbox $inbox): void
    {
        abort_unless((int) $inbox->help_desk_id === (int) $helpDesk->id, 404);
    }

    private function guardAddress(HelpDeskInbox $inbox, HelpDeskEmailAddress $emailAddress): void
    {
        abort_unless((int) $emailAddress->help_desk_inbox_id === (int) $inbox->id, 404);
    }
}
