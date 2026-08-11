<?php

namespace App\Services;

use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMembership;

/**
 * Workspace member capacity (invite spec §9, §10, §34, §65–§67).
 *
 * ProjectBlock has no subscription or plan model yet, so the limit comes from
 * `config('workspace.seat_limit')` (null = unlimited). This class is the seam: when billing
 * lands, `limitFor()` starts reading the workspace's plan and every caller — invitation
 * creation and membership activation — keeps working unchanged (see docs D-I3).
 *
 * Pending invitations count against capacity (§65), which is what stops an administrator
 * sending ten invitations into one free seat. Seats are released implicitly: a revoked or
 * expired invitation is no longer `pending`, so it stops being counted (§66).
 */
class WorkspaceSeatGuard
{
    /** Seats the workspace may fill, or null when unlimited. */
    public function limitFor(Workspace $workspace): ?int
    {
        $limit = config('workspace.seat_limit');

        return $limit === null ? null : max(0, (int) $limit);
    }

    /**
     * Active members + outstanding invitations (§10, §65).
     *
     * `$excludeInvitationId` leaves out one invitation. Acceptance needs that: the invitation
     * being accepted already reserved a seat, and the membership about to be created occupies
     * that same seat — counting both would refuse the last invitation a workspace sent.
     */
    public function used(Workspace $workspace, ?int $excludeInvitationId = null): int
    {
        $members = WorkspaceMembership::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', WorkspaceMembership::STATUS_ACTIVE)
            ->count();

        // Invitations are tenant-scoped; count them outside any ambient tenancy context so
        // the answer does not depend on which workspace happens to be active.
        $pending = WorkspaceInvitation::query()->withoutTenancy()
            ->where('tenant_id', $workspace->id)
            ->where('status', WorkspaceInvitation::STATUS_PENDING)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->when($excludeInvitationId !== null, fn ($q) => $q->whereKeyNot($excludeInvitationId))
            ->count();

        return $members + $pending;
    }

    /** Seats left, or null when unlimited. */
    public function remaining(Workspace $workspace): ?int
    {
        $limit = $this->limitFor($workspace);

        return $limit === null ? null : max(0, $limit - $this->used($workspace));
    }

    /**
     * Is there room for one more person?
     *
     * `$forUserId` excludes a user who is already an active member, so re-running an
     * acceptance that already succeeded is never blocked by the seat it itself occupies
     * (idempotency, §75). `$excludeInvitationId` does the same for the invitation being
     * accepted — see used().
     */
    public function hasSeat(Workspace $workspace, ?int $forUserId = null, ?int $excludeInvitationId = null): bool
    {
        $limit = $this->limitFor($workspace);
        if ($limit === null) {
            return true;
        }

        if ($forUserId !== null && $this->isActiveMember($workspace, $forUserId)) {
            return true;
        }

        return $this->used($workspace, $excludeInvitationId) < $limit;
    }

    private function isActiveMember(Workspace $workspace, int $userId): bool
    {
        return WorkspaceMembership::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $userId)
            ->where('status', WorkspaceMembership::STATUS_ACTIVE)
            ->exists();
    }
}
