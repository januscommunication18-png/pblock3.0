<?php

namespace App\Events;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Somebody accepted an invitation and is now a member of the workspace.
 *
 * The event exists so that things which are NOT the workspace can react to an acceptance
 * without the invitation service having to know about them (CLAUDE.md §6: events and listeners
 * where workflow actions need side effects). The Help Desk is the first of those — an invited
 * coworker gets their Help Desk membership from a listener (FR-1.5) — and putting that call
 * inside WorkspaceInvitationAccepter would have made the workspace's core invite flow depend on
 * a feature it should not know exists.
 */
class WorkspaceInvitationAccepted
{
    use Dispatchable;

    public function __construct(
        public readonly WorkspaceInvitation $invitation,
        public readonly User $user,
        public readonly Workspace $workspace,
        public readonly WorkspaceMembership $membership,
    ) {}
}
