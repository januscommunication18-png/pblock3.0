<?php

namespace App\Services\HelpCenter;

use App\Models\HelpCenterInbox;
use App\Models\HelpCenterSpace;

/**
 * Where the first-run wizard should pick up (docs/features/help-center.md §1, §21).
 *
 * The state is DERIVED from the data rather than stored as a step number (HC-D3):
 *
 *   no Space                                  → step 1, Create Space
 *   a Space but no Inbox                      → step 2, Set Up Inbox
 *   an Inbox that was never finished          → step 3, Inbound Configuration
 *   any Inbox finished                        → done; the Help Center opens normally
 *
 * A stored step would be a second record of facts the other tables already state, free to
 * disagree with them the moment anything is created or deleted by another path — and it would
 * have to be reset by hand when somebody abandoned the wizard halfway. Derived state cannot
 * drift, and resuming (§21's flow, interrupted) falls out of it for nothing.
 *
 * Must be called inside the workspace's tenancy context; the tenant scope on both models is
 * what confines these counts to one workspace.
 */
class HelpCenterOnboarding
{
    public const STEP_SPACE = 1;

    public const STEP_INBOX = 2;

    public const STEP_INBOUND = 3;

    public const DONE = 4;

    /**
     * Has this workspace finished onboarding at least once?
     *
     * ANY completed inbox counts, which is precisely what stops a second Space from restarting
     * the wizard (§4, §20 rule 12).
     */
    public function isComplete(): bool
    {
        return HelpCenterInbox::query()->whereNotNull('setup_completed_at')->exists();
    }

    /** The step the wizard should resume at. */
    public function step(): int
    {
        if ($this->isComplete()) {
            return self::DONE;
        }

        if (! HelpCenterSpace::query()->exists()) {
            return self::STEP_SPACE;
        }

        if (! HelpCenterInbox::query()->exists()) {
            return self::STEP_INBOX;
        }

        return self::STEP_INBOUND;
    }

    /**
     * The Space and Inbox the wizard is working on, if it has got that far.
     *
     * The OLDEST of each, not the newest: the wizard creates one of each, in order, and if a
     * second appeared from anywhere the run in progress is still the first.
     */
    public function space(): ?HelpCenterSpace
    {
        return HelpCenterSpace::query()->with('lead')->oldest('id')->first();
    }

    public function inbox(): ?HelpCenterInbox
    {
        return HelpCenterInbox::query()->with('emailAddresses')->oldest('id')->first();
    }

    /**
     * The four stages of §2's progress indicator, with where the user has got to.
     *
     * @return array<int, array<string, mixed>>
     */
    public function progress(?int $current = null): array
    {
        $current ??= $this->step();

        $stages = [
            self::STEP_SPACE => 'Create Space',
            self::STEP_INBOX => 'Set Up Inbox',
            self::STEP_INBOUND => 'Configure Inbound Email',
            self::DONE => 'Complete',
        ];

        $out = [];

        foreach ($stages as $number => $label) {
            $out[] = [
                'number' => $number,
                'label' => $label,
                'state' => match (true) {
                    $number < $current => 'done',
                    $number === $current => 'current',
                    default => 'todo',
                },
            ];
        }

        return $out;
    }
}
