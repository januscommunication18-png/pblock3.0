<?php

namespace App\Services\HelpCenter;

use App\Models\HelpCenterEmailAddress;
use App\Models\HelpCenterInbox;
use App\Models\HelpCenterSpace;
use App\Models\User;

/**
 * Creating an Inbox and attaching addresses to it
 * (docs/features/help-center.md §5, §6, §8).
 *
 * The Inbox and its inbound identifier are made together and never apart: §20 rule 4 says
 * "every Inbox has one unique ProjectBlock inbound address", and an Inbox that existed for even
 * a moment without one would be an Inbox mail could not reach.
 */
class HelpCenterInboxManager
{
    public function __construct(private readonly InboundAddressGenerator $inbound) {}

    /** @param  array{name: string}  $data */
    public function create(User $actor, HelpCenterSpace $space, array $data): HelpCenterInbox
    {
        return HelpCenterInbox::create([
            'help_center_space_id' => $space->id,
            'name' => trim($data['name']),
            'inbound_id' => $this->inbound->generate(),
            'created_by' => $actor->id,
            'position' => (int) HelpCenterInbox::query()
                ->where('help_center_space_id', $space->id)
                ->max('position') + 1,
        ]);
    }

    /**
     * Attach a customer-facing address (§6).
     *
     * Normalization lives on the model's mutator, so this does not repeat it — every path that
     * writes an address, including the ingestion of the next phase, gets the same treatment.
     */
    public function addAddress(User $actor, HelpCenterInbox $inbox, string $email, ?string $name = null): HelpCenterEmailAddress
    {
        $name = trim((string) $name);

        return $inbox->emailAddresses()->create([
            'tenant_id' => $inbox->tenant_id,
            'email' => $email,
            'name' => $name === '' ? null : $name,
            'status' => HelpCenterEmailAddress::STATUS_PENDING,
            'created_by' => $actor->id,
        ]);
    }

    /**
     * Finish the wizard for this Inbox (§12, HC-D3).
     *
     * Idempotent: re-running step 3 must not move the timestamp, or "when was this set up?"
     * becomes "when did somebody last look at the instructions?".
     */
    public function completeSetup(HelpCenterInbox $inbox): HelpCenterInbox
    {
        if (! $inbox->isSetUp()) {
            $inbox->forceFill(['setup_completed_at' => now()])->save();
        }

        return $inbox;
    }
}
