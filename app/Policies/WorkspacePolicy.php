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

    private function membership(User $user, Workspace $workspace): ?WorkspaceMembership
    {
        return WorkspaceMembership::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $user->id)
            ->where('status', WorkspaceMembership::STATUS_ACTIVE)
            ->first();
    }
}
