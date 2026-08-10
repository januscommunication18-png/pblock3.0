<?php

namespace App\Services;

use App\Models\OnboardingProfile;
use App\Models\User;

/**
 * Resolves where a user should go next based on onboarding state (LOGIN-005 / ONB-006).
 */
class OnboardingRouter
{
    /** Route name for the user's current position in the flow. */
    public function destinationFor(User $user): string
    {
        $profile = $user->onboardingProfile;

        if (! $profile) {
            return 'onboarding.profile';
        }

        return match ($profile->current_step) {
            OnboardingProfile::STEP_PROFILE   => 'onboarding.profile',
            OnboardingProfile::STEP_ROLE      => 'onboarding.role',
            OnboardingProfile::STEP_GOALS     => 'onboarding.goals',
            OnboardingProfile::STEP_COMPLETED => $this->postOnboardingRoute($user),
            default                           => 'onboarding.profile',
        };
    }

    /**
     * After onboarding is complete, route by workspace state (LOGIN-005).
     * Phase 3 (workspaces) is not built yet, so this returns the Phase 3 entry stub.
     */
    public function postOnboardingRoute(User $user): string
    {
        // TODO(Phase 3): zero workspace -> create; one -> active; many -> selector.
        return 'onboarding.done';
    }
}
