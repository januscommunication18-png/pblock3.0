<?php

namespace App\Policies;

use App\Models\HelpCenterSpace;
use App\Models\User;

/**
 * Who may configure the Help Center (docs/features/help-center.md §19).
 *
 * §19 names three roles and gives no schema for them; Phase 1 maps them onto authority this
 * application already has (HC-D4):
 *
 *   Help Desk Admin → workspace Owner/Admin — creates Spaces, manages any of them
 *   Space Lead      → the Space's own lead  — manages THAT Space and everything under it
 *   Agent           → any other active member — sees the Help Center, configures nothing
 *
 * Enforced here and consulted by every endpoint, because §19's rules are about what somebody may
 * DO, and hiding a button is presentation rather than security.
 */
class HelpCenterSpacePolicy
{
    /**
     * Creating a Space is a workspace-administration act (§19, Help Desk Admin).
     *
     * Not delegated to Space Leads: leading one Space is responsibility for that Space, and it
     * is not a licence to add more of them to somebody else's workspace.
     */
    public function create(User $user): bool
    {
        return $user->currentWorkspace !== null
            && $user->can('manageSettings', $user->currentWorkspace);
    }

    /**
     * Every active workspace member may look at a Space (§19, Agent).
     *
     * The tenant scope has already confined the query to this workspace, and reaching a Help
     * Center route at all requires an active membership, so arriving here with a Space in hand
     * is itself the answer. Per-Space access lists are the membership work of a later phase.
     */
    public function view(User $user, HelpCenterSpace $space): bool
    {
        return $user->currentWorkspace !== null
            && (string) $space->tenant_id === (string) $user->currentWorkspace->id;
    }

    /**
     * Changing the Space, its Inboxes, or their email addresses.
     *
     * Delegates to the model so the policy, the controllers and the payload flags that decide
     * whether to draw a button all resolve the same sentence.
     */
    public function update(User $user, HelpCenterSpace $space): bool
    {
        return $this->view($user, $space) && $space->manageableBy($user);
    }

    /** Archiving is management of the Space, on the same terms (§19). */
    public function archive(User $user, HelpCenterSpace $space): bool
    {
        return $this->update($user, $space);
    }

    /**
     * Deleting a Space is NOT ordinary management (P3 §20).
     *
     * It is workspace administration, and deliberately narrower than `update`: a Space Lead runs
     * their Space, which is not the same as being able to destroy it along with its Inbox, its
     * inbound address and every conversation that ever arrived there. The same reasoning makes
     * deleting a workspace owner-only in WorkspacePolicy.
     *
     * Archiving is what a Lead reaches for instead, and it is reversible.
     */
    public function delete(User $user, HelpCenterSpace $space): bool
    {
        return $this->view($user, $space)
            && $user->currentWorkspace !== null
            && $user->can('manageSettings', $user->currentWorkspace);
    }
}
