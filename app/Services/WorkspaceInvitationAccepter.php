<?php

namespace App\Services;

use App\Events\WorkspaceInvitationAccepted;
use App\Models\OnboardingProfile;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Turns a pending invitation into an active workspace membership (invite spec §33–§40, §91).
 *
 * The whole sequence runs in one transaction with the invitation row locked, which is what
 * makes acceptance idempotent (§75): a double submit, a refresh, or a retry after a failed
 * response all serialize on that row, and the second one finds the invitation already
 * accepted and returns the existing membership instead of creating a second one.
 *
 * Capacity is revalidated here, not just at invite time, because the last seat may have gone
 * between sending and accepting (§34/§67).
 */
class WorkspaceInvitationAccepter
{
    /** Why an invitation could not be accepted — each maps to a message in the UI. */
    public const BLOCKED_INVALID = 'invalid';

    public const BLOCKED_EMAIL_MISMATCH = 'email_mismatch';

    public const BLOCKED_WORKSPACE_UNAVAILABLE = 'workspace_unavailable';

    public const BLOCKED_NO_SEAT = 'no_seat';

    public function __construct(private readonly WorkspaceSeatGuard $seats) {}

    /**
     * Accept `$invitation` on behalf of `$user`.
     *
     * @return array{ok:bool, reason?:string, workspace?:Workspace, membership?:WorkspaceMembership}
     */
    public function accept(WorkspaceInvitation $invitation, User $user): array
    {
        $workspace = $invitation->workspace;

        // §69/§70: the workspace may have gone away or been archived since the invite was sent.
        if (! $workspace || ! $this->workspaceAcceptsMembers($workspace)) {
            return ['ok' => false, 'reason' => self::BLOCKED_WORKSPACE_UNAVAILABLE];
        }

        // §54/§91.8: an invitation belongs to one email address and cannot be redirected.
        if (! $this->emailMatches($invitation, $user)) {
            return ['ok' => false, 'reason' => self::BLOCKED_EMAIL_MISMATCH];
        }

        // §64: re-opening an accepted link must not re-process it. If the membership is
        // already there, this is a repeat submission — report success and let the caller
        // route the user into the workspace. An invitation still sitting at `pending` in that
        // situation (the user joined some other way) is retired here, so it stops holding a
        // seat and stops showing as outstanding on the Members screen.
        $existing = $this->activeMembership($workspace, $user);
        if ($existing) {
            if ($invitation->isPending()) {
                $invitation->forceFill([
                    'status' => WorkspaceInvitation::STATUS_ACCEPTED,
                    'accepted_at' => $invitation->accepted_at ?? now(),
                    'user_id' => $user->id,
                ])->save();

                // Only when this call is what retired the invitation — a repeat click on an
                // already-accepted link must not fire the side effects a second time.
                WorkspaceInvitationAccepted::dispatch($invitation, $user, $workspace, $existing);
            }

            return ['ok' => true, 'workspace' => $workspace, 'membership' => $existing];
        }

        if (! $invitation->isAcceptable()) {
            $invitation->markExpiredIfLapsed();

            return ['ok' => false, 'reason' => self::BLOCKED_INVALID];
        }

        // §34: revalidate capacity at acceptance, not only at invitation time. This
        // invitation's own reserved seat is the one being filled, so it is excluded.
        if (! $this->seats->hasSeat($workspace, $user->id, $invitation->id)) {
            Log::info('workspace.invitation.blocked', [
                'invitation_id' => $invitation->id, 'tenant_id' => $workspace->id, 'reason' => self::BLOCKED_NO_SEAT,
            ]);

            return ['ok' => false, 'reason' => self::BLOCKED_NO_SEAT];
        }

        $membership = DB::transaction(function () use ($invitation, $user, $workspace) {
            /** @var WorkspaceInvitation|null $locked */
            $locked = WorkspaceInvitation::query()->withoutTenancy()
                ->whereKey($invitation->id)->lockForUpdate()->first();

            // Lost the race: another request accepted it while we waited on the lock. Its
            // membership is the one that counts.
            if (! $locked || ! $locked->isAcceptable()) {
                return $this->activeMembership($workspace, $user);
            }

            // §40/§41: the role comes from the invitation as it stands now, so an admin who
            // changed it while the invitation was pending wins.
            $membership = WorkspaceMembership::updateOrCreate(
                ['workspace_id' => $workspace->id, 'user_id' => $user->id],
                [
                    'role' => $locked->role,
                    'status' => WorkspaceMembership::STATUS_ACTIVE,
                    'joined_at' => now(),
                ],
            );

            $locked->forceFill([
                'status' => WorkspaceInvitation::STATUS_ACCEPTED,
                'accepted_at' => now(),
                'user_id' => $user->id,
            ])->save();

            return $membership;
        });

        if (! $membership) {
            return ['ok' => false, 'reason' => self::BLOCKED_INVALID];
        }

        $this->settleUser($user, $workspace);

        /*
         * Anything that is not the workspace itself reacts here rather than being called from
         * inside this service (CLAUDE.md §6). The Help Desk's listener is what turns an invited
         * coworker into a Help Desk member (FR-1.5); this flow does not have to know that.
         */
        WorkspaceInvitationAccepted::dispatch($invitation, $user, $workspace, $membership);

        Log::info('workspace.invitation.accepted', [
            'invitation_id' => $invitation->id,
            'tenant_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => $membership->role,
        ]);

        return ['ok' => true, 'workspace' => $workspace, 'membership' => $membership];
    }

    /**
     * §51/§84: the invited workspace becomes the active one, and the first-workspace "invite
     * your teammates" step is closed out — an invited member is joining someone else's
     * workspace, so they must never be dropped into workspace setup.
     */
    private function settleUser(User $user, Workspace $workspace): void
    {
        $user->forceFill(['current_workspace_id' => $workspace->id])->save();

        OnboardingProfile::updateOrCreate(
            ['user_id' => $user->id],
            ['workspace_setup_completed_at' => now()],
        );
    }

    private function emailMatches(WorkspaceInvitation $invitation, User $user): bool
    {
        return strtolower(trim((string) $user->email)) === strtolower(trim((string) $invitation->email));
    }

    /** §68/§70: only a live workspace can take new members. */
    private function workspaceAcceptsMembers(Workspace $workspace): bool
    {
        $status = $workspace->status;

        return $status === null || $status === 'active';
    }

    private function activeMembership(Workspace $workspace, User $user): ?WorkspaceMembership
    {
        return WorkspaceMembership::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $user->id)
            ->where('status', WorkspaceMembership::STATUS_ACTIVE)
            ->first();
    }
}
