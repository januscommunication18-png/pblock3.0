<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;

/**
 * Authorizes project access (spec §4.3 / §6, PRJ-030/031/032). Visibility is enforced
 * here (and therefore in the controllers), never only hidden in the UI.
 *
 * Workspace role is resolved from the central workspace_memberships table (works without a
 * tenancy context). Workspace owners/admins retain administrative access to every project,
 * including private ones (PRJ-031). Standard members can see public projects; guests are
 * never granted access to a project just because it is public (PRJ-030) — they must be an
 * explicit project member.
 */
class ProjectPolicy
{
    /** Can the user open Workspace → Projects at all? Any active workspace member. */
    public function viewAny(User $user, Workspace $workspace): bool
    {
        return $this->workspaceRole($user, $workspace) !== null;
    }

    /**
     * Can the user create a project in this workspace (PRJ-020, roles §6)? Owner/Admin/Member
     * by default; Viewer and Guest cannot. Passed as `$user->can('create', [Project::class,
     * $workspace])`.
     */
    public function create(User $user, Workspace $workspace): bool
    {
        return in_array(
            $this->workspaceRole($user, $workspace),
            [WorkspaceMembership::ROLE_OWNER, 'admin', 'member'],
            true,
        );
    }

    /** Can the user open this specific project (PRJ-030/031)? */
    public function view(User $user, Project $project): bool
    {
        $role = $this->workspaceRole($user, $this->workspaceIdOf($project));

        // Not even a member of the owning workspace.
        if ($role === null) {
            return false;
        }

        // Workspace owners/admins keep administrative access to every project (PRJ-031).
        if (in_array($role, [WorkspaceMembership::ROLE_OWNER, 'admin'], true)) {
            return true;
        }

        // Explicit project members can always open it (public or private).
        if ($this->isProjectMember($user, $project)) {
            return true;
        }

        // Public projects are discoverable to standard members, but NOT to guests solely
        // because they are public (PRJ-030).
        return $project->isPublic() && $role !== 'guest';
    }

    /** Managing a project (settings/members/lifecycle) — later phases lean on this. */
    public function update(User $user, Project $project): bool
    {
        $role = $this->workspaceRole($user, $this->workspaceIdOf($project));

        if (in_array($role, [WorkspaceMembership::ROLE_OWNER, 'admin'], true)) {
            return true;
        }

        return ProjectMember::query()
            ->where('project_id', $project->id)
            ->where('user_id', $user->id)
            ->where('role', ProjectMember::ROLE_ADMIN)
            ->exists();
    }

    private function isProjectMember(User $user, Project $project): bool
    {
        return ProjectMember::query()
            ->where('project_id', $project->id)
            ->where('user_id', $user->id)
            ->exists();
    }

    /** Active workspace-membership role for the user, or null if not a member. */
    private function workspaceRole(User $user, Workspace|string $workspace): ?string
    {
        $workspaceId = $workspace instanceof Workspace ? $workspace->id : $workspace;

        return WorkspaceMembership::query()
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $user->id)
            ->where('status', WorkspaceMembership::STATUS_ACTIVE)
            ->value('role');
    }

    private function workspaceIdOf(Project $project): string
    {
        return (string) $project->tenant_id;
    }
}
