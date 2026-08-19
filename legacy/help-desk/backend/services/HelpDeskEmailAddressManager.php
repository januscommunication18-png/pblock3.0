<?php

namespace App\Services\HelpDesk;

use App\Models\HelpDesk;
use App\Models\HelpDeskEmailAddress;
use App\Models\HelpDeskInbox;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Connecting, verifying and retiring the addresses customers write to
 * (Inbound Email requirements §5, §7, §8, §9, §10).
 *
 * Every rule about what a connection IS lives here rather than in the controller, because two of
 * them are written from two directions: a person presses Verify on a screen, and a message
 * arrives on a queue hours later with nobody watching. Those are the same state machine, and
 * splitting it across a controller and an ingestor would be two answers to "is this connected?".
 *
 * The state machine, in full (§7):
 *
 *   setup_required ──Verify──► waiting_for_email ──mail arrives──► connected
 *          │                          │                                │
 *          └──────mail arrives────────┘                                │
 *                                     └──window lapses──► error ───────┘
 *                                                                (mail arrives)
 *   any ──administrator──► disabled ──administrator──► setup_required
 *
 * Note which transitions have an actor and which do not. `connected` is only ever reached by a
 * message ARRIVING — nothing an administrator presses can assert it, because the forwarding rule
 * that has to work lives in somebody else's mail provider and this application cannot see it.
 */
class HelpDeskEmailAddressManager
{
    public function __construct(private readonly HelpDeskActivityRecorder $activity) {}

    /**
     * Connect a customer-facing address to an inbox (§9).
     *
     * A soft-deleted row for the same address is RESTORED rather than duplicated: removing an
     * address and adding it again is something administrators do while setting forwarding up,
     * and a second row would give one address two statuses and two "last email" times.
     */
    public function connect(HelpDesk $helpDesk, HelpDeskInbox $inbox, string $address, ?User $actor = null): HelpDeskEmailAddress
    {
        $address = strtolower(trim($address));

        $existing = HelpDeskEmailAddress::withTrashed()
            ->where('help_desk_id', $helpDesk->id)
            ->where('address', $address)
            ->first();

        if ($existing && $existing->trashed()) {
            $existing->restore();
            $existing->forceFill([
                'help_desk_inbox_id' => $inbox->id,
                // Its old status described a forwarding rule that may since have been taken
                // down. Back to the start: the only thing that can say otherwise is mail.
                'status' => HelpDeskEmailAddress::STATUS_SETUP_REQUIRED,
                'verification_started_at' => null,
                'verified_at' => null,
            ])->save();

            $this->activity->emailAddressAdded($helpDesk, $actor, $existing->fresh(), $inbox);

            return $existing->fresh();
        }

        if ($existing) {
            /*
             * The same address twice in one Help Desk. Refused as a field error rather than
             * silently accepted: two rows would both claim the next message that mentions it,
             * and whichever won would be arbitrary.
             *
             * Scoped to this Help Desk on purpose — another workspace connecting the same
             * address is not a conflict, because this column routes nothing. The GENERATED
             * address is what routes, and that one is unique everywhere.
             */
            throw ValidationException::withMessages([
                'address' => $existing->help_desk_inbox_id === $inbox->id
                    ? 'That address is already connected to this inbox.'
                    : 'That address is already connected to another inbox in this Help Desk.',
            ]);
        }

        $created = HelpDeskEmailAddress::create([
            'tenant_id' => $helpDesk->tenant_id,
            'help_desk_id' => $helpDesk->id,
            'help_desk_inbox_id' => $inbox->id,
            // §7's initial status. Nothing has been forwarded yet, and saying "connected"
            // before a message has arrived would be this screen guessing.
            'status' => HelpDeskEmailAddress::STATUS_SETUP_REQUIRED,
            'address' => $address,
            'created_by' => $actor?->id,
        ]);

        $this->activity->emailAddressAdded($helpDesk, $actor, $created, $inbox);

        return $created;
    }

    /**
     * Start watching for a test message (§8, "Verify Connection").
     *
     * This does not send anything. It cannot: a message that proves the forwarding works has to
     * travel THROUGH the customer's mail provider, so the only thing that can send it is a
     * person writing to their own support address. All this does is start the clock, so a
     * forward that never arrives can be called an error instead of waiting for ever.
     */
    public function verify(HelpDeskEmailAddress $emailAddress): HelpDeskEmailAddress
    {
        if ($emailAddress->isDisabled()) {
            throw ValidationException::withMessages([
                'status' => 'Enable this address before verifying it.',
            ]);
        }

        $emailAddress->forceFill([
            'status' => HelpDeskEmailAddress::STATUS_WAITING,
            'verification_started_at' => now(),
        ])->save();

        return $emailAddress->fresh();
    }

    /**
     * Switch an address off, or back on (§10's Disable action).
     *
     * Bookkeeping, not an off switch, and the screen says so: mail forwarded to the inbox still
     * arrives, because only the customer's mail provider can stop that. What this changes is
     * whether we count the address as one this Help Desk is looking after.
     */
    public function setDisabled(HelpDesk $helpDesk, HelpDeskEmailAddress $emailAddress, bool $disabled, ?User $actor = null): HelpDeskEmailAddress
    {
        $emailAddress->forceFill([
            'status' => $disabled
                ? HelpDeskEmailAddress::STATUS_DISABLED
                // Re-enabled at the start, not at whatever it was: the forwarding may have been
                // removed while it was off, and the only thing that can prove otherwise is mail.
                : HelpDeskEmailAddress::STATUS_SETUP_REQUIRED,
            'verification_started_at' => null,
        ])->save();

        $this->activity->emailAddressStatusChanged($helpDesk, $actor, $emailAddress->fresh());

        return $emailAddress->fresh();
    }

    /** Remove the connection (§10's Delete). Soft — see `connect()` on why it can come back. */
    public function remove(HelpDesk $helpDesk, HelpDeskEmailAddress $emailAddress, ?User $actor = null): void
    {
        $address = (string) $emailAddress->address;

        $emailAddress->delete();

        $this->activity->emailAddressRemoved($helpDesk, $actor, $address);
    }

    /**
     * A message arrived at this inbox. Credit the address it was addressed to (§7, §8).
     *
     * The ONE thing that can say a connection works, called from ingestion with nobody watching.
     * The match is against the addresses the message was actually sent to — its To, Cc and the
     * recipient the mail server delivered it as — because that is the customer address the
     * forwarding rule preserved on its way here.
     *
     * An arrival that matches nothing is deliberately silent. It is the ordinary case: mail sent
     * straight to the generated address, a forwarder that rewrites the recipient, an alias
     * nobody connected here. Crediting "the only address on this inbox" in that case would be
     * this screen reporting a forwarding rule works on the evidence of a message that never went
     * through it.
     *
     * @param  array<int, string>  $recipients  every address the message was sent to
     */
    public function recordArrival(HelpDeskInbox $inbox, array $recipients): void
    {
        $recipients = array_values(array_filter(array_map(
            fn ($address) => strtolower(trim((string) $address)),
            $recipients,
        )));

        if ($recipients === []) {
            return;
        }

        $matches = HelpDeskEmailAddress::query()
            ->where('help_desk_inbox_id', $inbox->id)
            ->whereIn('address', $recipients)
            ->get();

        foreach ($matches as $emailAddress) {
            $connecting = ! $emailAddress->isConnected() && ! $emailAddress->isDisabled();

            $emailAddress->forceFill(array_merge(
                ['last_email_at' => now()],
                // A DISABLED address is left disabled. Somebody switched it off on purpose, and
                // mail still arriving is exactly what they were told would happen.
                $emailAddress->isDisabled() ? [] : [
                    'status' => HelpDeskEmailAddress::STATUS_CONNECTED,
                    'verified_at' => $emailAddress->verified_at ?? now(),
                ],
            ))->save();

            if ($connecting) {
                // Once, on the transition. Every subsequent message is traffic, and a stream
                // that records traffic is a stream nobody reads.
                $this->activity->emailAddressConnected($inbox->helpDesk, $emailAddress->fresh(), $inbox);
            }
        }
    }
}
