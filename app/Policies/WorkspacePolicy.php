<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;

/**
 * Authorizes workspace access against ACTIVE membership (spec §7/§8). All checks resolve
 * from the central workspace_memberships table, so they work without a tenancy context.
 */
class WorkspacePolicy
{
    /** Any active member may view the workspace. */
    public function view(User $user, Workspace $workspace): bool
    {
        return $this->membership($user, $workspace) !== null;
    }

    /** Owners and admins may manage settings / invite teammates (spec §6). */
    public function invite(User $user, Workspace $workspace): bool
    {
        $membership = $this->membership($user, $workspace);

        return $membership !== null
            && in_array($membership->role, ['owner', 'admin'], true);
    }

    public function update(User $user, Workspace $workspace): bool
    {
        return $this->invite($user, $workspace);
    }

    /** Managing workspace settings requires owner/admin (spec §3 / §13). */
    public function manageSettings(User $user, Workspace $workspace): bool
    {
        return $this->update($user, $workspace);
    }

    /**
     * Permanently deleting the workspace is OWNER-ONLY (spec §4 / SET-G-008): the danger
     * zone is reserved for the highest workspace authority, never a plain admin.
     */
    public function delete(User $user, Workspace $workspace): bool
    {
        $membership = $this->membership($user, $workspace);

        return $membership !== null && $membership->role === WorkspaceMembership::ROLE_OWNER;
    }

    /** Removing another member requires owner/admin (spec §5 / SET-M-009). */
    public function removeMember(User $user, Workspace $workspace): bool
    {
        return $this->update($user, $workspace);
    }

    /**
     * May this person act on THAT member — change their role, remove them, deactivate them?
     *
     * Three rules, in order, and all of them server-side (§14):
     *   1. the actor must administer the workspace at all;
     *   2. they must strictly OUTRANK the target, so an admin cannot act on another admin and
     *      certainly not on an owner;
     *   3. the last owner is untouchable, even by themselves.
     *
     * An owner acting on another owner is allowed only while a third owner remains — which
     * rule 3 already expresses, so it is not restated here.
     */
    public function manageMember(User $user, Workspace $workspace, WorkspaceMembership $target): bool
    {
        $actor = $this->membership($user, $workspace);

        if ($actor === null || ! $this->invite($user, $workspace)) {
            return false;
        }

        if ($target->isLastOwner()) {
            return false;
        }

        // Acting on yourself is not management — leaving and self-demotion are their own rules.
        if ((int) $actor->id === (int) $target->id) {
            return false;
        }

        /*
         * An OWNER may act on anyone, including another owner — §11 lets an owner assign every
         * role, and the only thing protecting an owner is the last-owner rule already applied
         * above. Everybody else must STRICTLY outrank the target, so an admin cannot act on
         * another admin.
         */
        return $actor->isOwner() || $actor->outranks($target);
    }

    /**
     * May this person hand out that role?
     *
     * Only an owner may create another owner (§11). Everybody else may assign roles strictly
     * below their own, so an admin can make a member or a guest and never another admin.
     */
    public function assignRole(User $user, Workspace $workspace, string $role): bool
    {
        $actor = $this->membership($user, $workspace);

        if ($actor === null || ! $this->invite($user, $workspace)) {
            return false;
        }

        if ($role === WorkspaceMembership::ROLE_OWNER) {
            return $actor->isOwner();
        }

        return $actor->rank() > WorkspaceMembership::rankOf($role);
    }

    /** Transferring the workspace is owner-only (§4), like deleting it. */
    public function transferOwnership(User $user, Workspace $workspace): bool
    {
        return $this->delete($user, $workspace);
    }

    private function membership(User $user, Workspace $workspace): ?WorkspaceMembership
    {
        return WorkspaceMembership::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $user->id)
            ->where('status', WorkspaceMembership::STATUS_ACTIVE)
            ->first();
    }
}
