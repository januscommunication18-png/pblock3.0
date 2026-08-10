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
            OnboardingProfile::STEP_PROFILE => 'onboarding.profile',
            OnboardingProfile::STEP_ROLE => 'onboarding.role',
            OnboardingProfile::STEP_GOALS => 'onboarding.goals',
            OnboardingProfile::STEP_COMPLETED => $this->postOnboardingRoute($user),
            default => 'onboarding.profile',
        };
    }

    /**
     * After personalization (profile/role/goals) is complete, route by workspace state
     * (LOGIN-005 / spec §3, §9):
     *   - no workspace yet          -> first-workspace onboarding
     *   - workspace but invite step not finished/skipped -> resume invite step
     *   - otherwise                 -> welcome / get-started home
     */
    public function postOnboardingRoute(User $user): string
    {
        if ($user->workspaces()->count() === 0) {
            return 'onboarding.workspace';
        }

        $profile = $user->onboardingProfile;
        if (! $profile || ! $profile->hasCompletedWorkspaceSetup()) {
            return 'onboarding.invite';
        }

        return 'welcome';
    }
}
