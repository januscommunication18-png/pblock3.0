<?php

namespace App\Services;

use App\Mail\WorkspaceInvitationMail;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Persists teammate invitations for a workspace and emails them (spec §6, invite spec §8–§17).
 *
 * Invitations are TENANT-SCOPED, so this runs inside the target workspace's tenancy
 * context: BelongsToTenant then stamps tenant_id automatically and duplicate/pending
 * lookups are confined to the workspace. Each row is handled independently — an invalid
 * or duplicate row never discards the valid ones (spec §9, INV-004).
 *
 * Each accepted row gets a fresh raw token; only its SHA-256 hash is stored, and the raw value
 * leaves this class exactly once, inside the emailed link (invite spec §13).
 *
 * @phpstan-type InviteRow array{email:string, role:string}
 */
class WorkspaceInviter
{
    public function __construct(private readonly WorkspaceSeatGuard $seats) {}

    /**
     * @param  array<int, array{email:string, role:string}>  $rows
     * @return array<int, array{email:string, role:string, status:string}> per-recipient result
     */
    public function invite(Workspace $workspace, User $inviter, array $rows): array
    {
        // Run inside the workspace's tenancy context (atomic; reverts to the prior context).
        return $workspace->run(function () use ($workspace, $inviter, $rows) {
            $results = [];
            $seenThisRequest = [];
            $inviteRoles = config('workspace.invite_roles');
            $expiryDays = (int) config('workspace.invitation_expiry_days', 14);

            foreach ($rows as $row) {
                $email = strtolower(trim((string) ($row['email'] ?? '')));
                $role = (string) ($row['role'] ?? '');

                if ($email === '') {
                    continue; // blank row — ignore silently
                }

                // Per-row email validation (spec §9): a bad email is flagged, not fatal.
                if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $results[] = ['email' => $email, 'role' => $role, 'status' => 'invalid_email'];

                    continue;
                }

                if (! in_array($role, $inviteRoles, true)) {
                    $results[] = ['email' => $email, 'role' => $role, 'status' => 'invalid_role'];

                    continue;
                }

                if (isset($seenThisRequest[$email])) {
                    $results[] = ['email' => $email, 'role' => $role, 'status' => 'duplicate'];

                    continue;
                }
                $seenThisRequest[$email] = true;

                // Existing member of this workspace (spec §9, invite spec §8.1/§8.3). A
                // suspended membership is reported separately: re-inviting must not create a
                // second membership, the administrator has to reactivate the existing one.
                $membership = WorkspaceMembership::query()
                    ->where('workspace_id', $workspace->id)
                    ->whereHas('user', fn ($q) => $q->where('email', $email))
                    ->first();
                if ($membership) {
                    $results[] = [
                        'email' => $email,
                        'role' => $role,
                        'status' => $membership->status === WorkspaceMembership::STATUS_ACTIVE
                            ? 'already_member'
                            : 'suspended_member',
                    ];

                    continue;
                }

                // Don't create a second active invite for the same workspace/email (INV-004).
                // Tenant scope confines this to the current workspace automatically. A lapsed
                // invitation is retired first, so an expired one never blocks a fresh invite.
                $existing = WorkspaceInvitation::query()
                    ->where('email', $email)
                    ->where('status', WorkspaceInvitation::STATUS_PENDING)
                    ->first();
                if ($existing && ! $existing->markExpiredIfLapsed()) {
                    $results[] = ['email' => $email, 'role' => $role, 'status' => 'already_invited'];

                    continue;
                }

                // Capacity is checked per row, not once per request, because each invitation
                // sent inside this loop consumes a seat (invite spec §10/§65).
                if (! $this->seats->hasSeat($workspace)) {
                    $results[] = ['email' => $email, 'role' => $role, 'status' => 'no_seats'];

                    continue;
                }

                $token = WorkspaceInvitation::newToken();

                $invitation = DB::transaction(fn () => WorkspaceInvitation::create([
                    // tenant_id is stamped by BelongsToTenant from the active tenancy context.
                    'email' => $email,
                    'role' => $role,
                    'inviter_user_id' => $inviter->id,
                    'token' => WorkspaceInvitation::hashToken($token),
                    'status' => WorkspaceInvitation::STATUS_PENDING,
                    'expires_at' => now()->addDays($expiryDays),
                ]));

                $this->sendInvitationEmail($workspace, $inviter, $invitation, $token);

                $results[] = ['email' => $email, 'role' => $role, 'status' => 'invited'];
            }

            return $results;
        });
    }

    /**
     * Queue the invitation email (CLAUDE.md §11). Delivery failure must not roll back the
     * invitation — the row is what the Members screen and the resend action work from — so
     * this sits outside the transaction and reports rather than throws.
     */
    private function sendInvitationEmail(
        Workspace $workspace,
        User $inviter,
        WorkspaceInvitation $invitation,
        string $rawToken,
    ): void {
        $roleLabels = config('workspace.roles');

        try {
            // sendNow: an invitation nobody receives is an invitation that did not happen,
            // and it must not depend on a queue worker being up. See WorkItemStatusNotifier
            // for the trade this makes across the application.
            Mail::to($invitation->email)->sendNow(new WorkspaceInvitationMail(
                workspaceName: (string) $workspace->name,
                inviterName: $inviter->displayName(),
                invitedEmail: $invitation->email,
                roleLabel: $roleLabels[$invitation->role] ?? ucfirst($invitation->role),
                acceptUrl: route('invitations.show', ['token' => $rawToken]),
                expiresOn: optional($invitation->expires_at)->format('F j, Y') ?? '',
                workspaceLogoUrl: $workspace->logo_url,
            ));
        } catch (\Throwable $e) {
            // Never log the token or the link — both carry the raw secret.
            Log::error('workspace.invitation.email_failed', [
                'invitation_id' => $invitation->id,
                'tenant_id' => $invitation->tenant_id,
                'reason' => $e->getMessage(),
            ]);
        }
    }
}
