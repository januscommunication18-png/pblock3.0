<?php

namespace App\Services\HelpCenter;

use App\Models\HelpCenterSetupDraft;
use App\Models\HelpCenterSpaceSettings;
use App\Models\HelpCenterStatus;
use App\Models\User;
use App\Models\Workspace;

/**
 * The six-step wizard's working state (docs/features/help-center.md, P2 §2, HC-D11).
 *
 * Everything typed before Step 6 is confirmed lives in one `help_center_setup_drafts` row and
 * nowhere else, so abandoning the wizard leaves the workspace with no Space rather than a
 * half-configured one already in the navigation.
 *
 * This class owns three things the rest of the module should not have to think about: getting
 * or creating that row, the SHAPE of an empty draft, and the one value that must be allocated
 * before Step 6 — the inbound identifier (HC-D12).
 */
class SetupDraftStore
{
    /** The payload keys, one per step. */
    public const SPACE = 'space';

    public const TEAM = 'team';

    public const INBOX = 'inbox';

    public const WORKFLOW = 'workflow';

    public const SETTINGS = 'settings';

    public const TOTAL_STEPS = 6;

    public function __construct(private readonly InboundAddressGenerator $inbound) {}

    /**
     * This person's draft for this workspace, created empty if they have not started.
     *
     * Per USER, not per workspace: two administrators setting up at once are filling in two
     * different forms, and a shared row would have them overwriting each other mid-sentence.
     */
    public function for(Workspace $workspace, User $user): HelpCenterSetupDraft
    {
        $draft = HelpCenterSetupDraft::query()
            ->where('user_id', $user->id)
            ->first();

        if ($draft !== null) {
            return $draft;
        }

        return HelpCenterSetupDraft::create([
            'tenant_id' => $workspace->id,
            'user_id' => $user->id,
            'step' => 1,
            'payload' => $this->blank(),
        ]);
    }

    /**
     * An untouched draft.
     *
     * Stated once, here, because three things need to agree about it: the wizard rendering an
     * empty form, the validator deciding whether a step is complete, and the commit reading a
     * section the user never visited. A missing key and an empty key must not behave
     * differently.
     *
     * @return array<string, mixed>
     */
    public function blank(): array
    {
        return [
            self::SPACE => [
                'name' => '',
                'description' => '',
                'types' => [],
                'department_groups' => [],
                'lead_user_id' => null,
            ],
            self::TEAM => [
                // Each: {user_id|null, email, role, department_groups[]}
                //
                // `role` is the WORKSPACE role the person is invited with (P2 §8, HC-D19). It is
                // ignored for somebody who is already a member — they have a role, and Step 2 is
                // not the place to change it.
                'members' => [],
            ],
            self::INBOX => [
                'name' => '',
                'addresses' => [],
                // Allocated on first reaching Step 3 and then left alone (HC-D12).
                'inbound_id' => null,
            ],
            self::WORKFLOW => [
                // Open and Closed are present from the start — every Space has them, and Step 4
                // shows them before anything is saved (P2 §13, §15).
                'statuses' => HelpCenterStatus::systemDefaults(),
            ],
            self::SETTINGS => [
                'metadata' => HelpCenterSpaceSettings::defaultMetadata(),
                'auto_bcc_enabled' => false,
                'auto_bcc_email' => '',
                'reassign_enabled' => false,
                'reassign_hours' => 0,
                'reassign_minutes' => 0,
                'reassign_destination' => HelpCenterSpaceSettings::DESTINATION_UNASSIGNED,
                'auto_follow_mentions' => true,
            ],
        ];
    }

    /**
     * Save one step's values and remember how far the user has got.
     *
     * `reach()` only ever moves forward, so going Back to fix step 1 does not throw away the
     * fact that steps 2 and 3 are already filled in.
     *
     * @param  array<string, mixed>  $values
     */
    public function save(HelpCenterSetupDraft $draft, string $section, array $values, int $nextStep): HelpCenterSetupDraft
    {
        $draft->putSection($section, $values)->reach($nextStep)->save();

        return $draft;
    }

    /**
     * The inbound identifier for this run, allocated once (HC-D12).
     *
     * P2 §10: "Do not regenerate the Inbox ID unnecessarily when navigating back and forth."
     * The address a user copies at 10:00 has to be the address that exists at 10:05, so it is
     * reserved the first time Step 3 is reached and then read back unchanged.
     *
     * Collision is re-checked at COMMIT rather than here — a token free when the draft was
     * started can have been issued to somebody else's Space by the time it is confirmed.
     */
    public function inboundId(HelpCenterSetupDraft $draft): string
    {
        $inbox = $draft->section(self::INBOX);

        if (! empty($inbox['inbound_id'])) {
            return (string) $inbox['inbound_id'];
        }

        $inbox['inbound_id'] = $this->inbound->generate();

        $draft->putSection(self::INBOX, $inbox)->save();

        return $inbox['inbound_id'];
    }

    /** The whole draft, filled out with anything a blank one would have. */
    /** @return array<string, mixed> */
    public function payload(HelpCenterSetupDraft $draft): array
    {
        $blank = $this->blank();
        $saved = (array) ($draft->payload ?? []);
        $out = [];

        // Section by section, not a deep merge: a step's values REPLACE the defaults for that
        // step, so an emptied list stays empty instead of springing back to its default.
        foreach ($blank as $section => $defaults) {
            $out[$section] = array_key_exists($section, $saved) && is_array($saved[$section])
                ? $saved[$section] + $defaults
                : $defaults;
        }

        return $out;
    }

    /** Throw the run away — used by Cancel Setup, and by nothing else. */
    public function discard(HelpCenterSetupDraft $draft): void
    {
        $draft->delete();
    }
}
