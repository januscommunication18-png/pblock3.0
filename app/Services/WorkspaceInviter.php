<?php

namespace App\Services;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Persists pending teammate invitations for a workspace (spec §6).
 *
 * Invitations are TENANT-SCOPED, so this runs inside the target workspace's tenancy
 * context: BelongsToTenant then stamps tenant_id automatically and duplicate/pending
 * lookups are confined to the workspace. Each row is handled independently — an invalid
 * or duplicate row never discards the valid ones (spec §9, INV-004). No email is sent in
 * this phase; rows land as pending and surface later under Settings > Members.
 *
 * @phpstan-type InviteRow array{email:string, role:string}
 */
class WorkspaceInviter
{
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

                // Existing active member of this workspace (spec §9).
                $alreadyMember = WorkspaceMembership::query()
                    ->where('workspace_id', $workspace->id)
                    ->whereHas('user', fn ($q) => $q->where('email', $email))
                    ->exists();
                if ($alreadyMember) {
                    $results[] = ['email' => $email, 'role' => $role, 'status' => 'already_member'];

                    continue;
                }

                // Don't create a second active invite for the same workspace/email (INV-004).
                // Tenant scope confines this to the current workspace automatically.
                $existing = WorkspaceInvitation::query()
                    ->where('email', $email)
                    ->where('status', WorkspaceInvitation::STATUS_PENDING)
                    ->first();
                if ($existing) {
                    $results[] = ['email' => $email, 'role' => $role, 'status' => 'already_invited'];

                    continue;
                }

                DB::transaction(function () use ($email, $role, $inviter, $expiryDays) {
                    WorkspaceInvitation::create([
                        // tenant_id is stamped by BelongsToTenant from the active tenancy context.
                        'email' => $email,
                        'role' => $role,
                        'inviter_user_id' => $inviter->id,
                        'token' => hash('sha256', Str::random(48)),
                        'status' => WorkspaceInvitation::STATUS_PENDING,
                        'expires_at' => now()->addDays($expiryDays),
                    ]);
                });

                $results[] = ['email' => $email, 'role' => $role, 'status' => 'invited'];
            }

            return $results;
        });
    }
}
