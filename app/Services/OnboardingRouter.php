<?php

namespace App\Services;

use App\Models\OnboardingProfile;
use App\Models\User;
use App\Models\WorkspaceInvitation;

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
     *   - invitation waiting        -> join the inviting workspace
     *   - no workspace yet          -> first-workspace onboarding
     *   - workspace but invite step not finished/skipped -> resume invite step
     *   - otherwise                 -> welcome / get-started home
     */
    public function postOnboardingRoute(User $user): string
    {
        // An invited user joins an existing workspace and must never be shown Create
        // Workspace (invite spec §29/§51). They are routed to the join step when they arrived
        // from an invitation link this session, or whenever they have no workspace of their
        // own — so a pending invitation can never capture the navigation of someone who is
        // already a member elsewhere (docs D-I5).
        $followingLink = session()->has('invitation_token');
        if (($followingLink || $user->workspaces()->count() === 0) && $this->hasPendingInvitation($user)) {
            return 'invitations.pending';
        }

        if ($user->workspaces()->count() === 0) {
            return 'onboarding.workspace';
        }

        $profile = $user->onboardingProfile;
        if (! $profile || ! $profile->hasCompletedWorkspaceSetup()) {
            return 'onboarding.invite';
        }

        return 'welcome';
    }

    /** An unexpired invitation is waiting for this user's address (invite spec §32). */
    private function hasPendingInvitation(User $user): bool
    {
        return WorkspaceInvitation::pendingFor((string) $user->email)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->exists();
    }
}
