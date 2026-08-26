<?php

namespace App\Services;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\DB;

/**
 * Permanently deletes a workspace and everything owned by it (spec §4 / SET-G-008).
 *
 * Deleting the tenant row cascades (ON DELETE CASCADE) to memberships, invitations and all
 * tenant-scoped settings tables. Any member whose current_workspace_id pointed at the
 * deleted workspace is repaired to another workspace they still belong to (or null), so the
 * app shell never dereferences a dangling workspace. Runs in one transaction (atomic).
 */
class WorkspaceDeleter
{
    public function delete(Workspace $workspace): void
    {
        DB::transaction(function () use ($workspace) {
            // Capture who had this workspace active BEFORE deleting it — the
            // users.current_workspace_id foreign key nulls itself on delete, so this must
            // be read up front.
            $affectedUserIds = User::query()
                ->where('current_workspace_id', $workspace->id)
                ->pluck('id')->all();

            // Cascades to memberships, invitations, workspace_settings, project_states, etc.
            $workspace->delete();

            // Repair the active-workspace pointer: point each affected user at another
            // workspace they still belong to (or leave null). Memberships are central, so
            // this resolves without a tenancy context and without the deleted tenant row.
            foreach (User::query()->whereIn('id', $affectedUserIds)->get() as $user) {
                // ACTIVE only: repairing the pointer must not aim somebody at a workspace they
                // are suspended from, which the tenancy middleware would refuse on the next
                // request anyway (docs/features/workspace-access-control.md).
                $next = WorkspaceMembership::query()
                    ->where('user_id', $user->id)
                    ->where('workspace_id', '!=', $workspace->id)
                    ->where('status', WorkspaceMembership::STATUS_ACTIVE)
                    ->value('workspace_id');
                $user->forceFill(['current_workspace_id' => $next])->save();
            }
        });
    }
}
