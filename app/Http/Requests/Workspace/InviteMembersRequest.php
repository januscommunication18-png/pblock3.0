<?php

namespace App\Http\Requests\Workspace;

use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMembership;
use Illuminate\Contracts\Validation\Validator;

/**
 * Validation for the in-app invite screen (reachable from the welcome home).
 *
 * Stricter than the lenient onboarding step: it blocks the submission and reports a
 * per-row error when an email is already an active member or already has a pending
 * invitation, so the same teammate can't be invited twice. Row values are preserved on
 * redirect so the user can fix just the flagged rows.
 */
class InviteMembersRequest extends StoreInvitesRequest
{
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $workspace = $this->user()?->currentWorkspace;
            if (! $workspace) {
                return;
            }

            $rows = (array) $this->input('invites', []);
            $seen = [];
            $anyEmail = false;

            foreach ($rows as $i => $row) {
                $email = strtolower(trim((string) ($row['email'] ?? '')));
                $role = (string) ($row['role'] ?? '');

                if ($email === '') {
                    continue; // blank rows are ignored
                }
                $anyEmail = true;

                if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $validator->errors()->add("invites.{$i}.email", 'Enter a valid email address.');

                    continue;
                }

                if ($role === '') {
                    $validator->errors()->add("invites.{$i}.role", 'Select a role.');
                }

                // Duplicate within this same submission.
                if (isset($seen[$email])) {
                    $validator->errors()->add("invites.{$i}.email", 'This email is listed more than once.');

                    continue;
                }
                $seen[$email] = true;

                // Already an active member of this workspace.
                $isMember = WorkspaceMembership::query()
                    ->where('workspace_id', $workspace->id)
                    ->whereHas('user', fn ($q) => $q->where('email', $email))
                    ->exists();
                if ($isMember) {
                    $validator->errors()->add("invites.{$i}.email", 'This person is already a member of this workspace.');

                    continue;
                }

                // Already has a pending invitation (no tenancy context here, so filter by tenant_id).
                $alreadyInvited = WorkspaceInvitation::query()
                    ->where('tenant_id', $workspace->id)
                    ->where('email', $email)
                    ->where('status', WorkspaceInvitation::STATUS_PENDING)
                    ->exists();
                if ($alreadyInvited) {
                    $validator->errors()->add("invites.{$i}.email", 'This person has already been invited.');
                }
            }

            if (! $anyEmail) {
                $validator->errors()->add('invites', 'Add at least one teammate to invite.');
            }
        });
    }
}
