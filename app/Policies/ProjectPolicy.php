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

    /**
     * Can the user open this specific project?
     *
     * Two layers (Project Member Management §16): workspace membership decides whether they
     * reach the workspace at all, project membership decides whether they reach THIS project.
     *
     * §38 is the governing rule — *Workspace Member ≠ Automatic Project Member*. A workspace
     * member gets no access to a project until they are explicitly added to it (§18), which
     * **supersedes Phase 4's PRJ-030**, where a public project was visible to every standard
     * member. `visibility` no longer grants access on its own.
     */
    public function view(User $user, Project $project): bool
    {
        $role = $this->workspaceRole($user, $this->workspaceIdOf($project));

        // Not even a member of the owning workspace.
        if ($role === null) {
            return false;
        }

        // §18: Workspace Owner/Admin keep administrative visibility across every project —
        // they must, since §17 lets them manage any project's members.
        if (in_array($role, [WorkspaceMembership::ROLE_OWNER, 'admin'], true)) {
            return true;
        }

        // Everyone else needs an explicit project membership (§18), which the project's
        // creator receives automatically (§19).
        return $this->isProjectMember($user, $project);
    }

    /** Managing a project (settings/members/lifecycle) — later phases lean on this. */
    public function update(User $user, Project $project): bool
    {
        return $this->canManage($user, $project);
    }

    /**
     * Alias of update, for the `can('manage', $project)` calls in the project-settings
     * controllers. Laravel denies an ability whose policy method is missing, so without
     * this every Project Settings section returned 403 — including for workspace owners.
     */
    public function manage(User $user, Project $project): bool
    {
        return $this->canManage($user, $project);
    }

    /**
     * Permanent delete (PRJ-047) — manage rights, same as update. The typed-identifier
     * confirmation is enforced in the controller, not here.
     */
    public function delete(User $user, Project $project): bool
    {
        return $this->canManage($user, $project);
    }

    /**
     * Who may manage a project's members (§17): Workspace Owner, Workspace Admin, or this
     * project's own Admin. A Workspace Manager only qualifies when they are also Project
     * Admin — which this covers, since the check is on the project role, not the workspace
     * one. Contributors, Commenters and Guests never qualify.
     *
     * Passed as `$user->can('manageMembers', $project)`.
     */
    public function manageMembers(User $user, Project $project): bool
    {
        return $this->canManage($user, $project);
    }

    /**
     * Manage rights: workspace owners/admins can manage any project in the workspace;
     * otherwise the user must be an admin of this specific project.
     */
    private function canManage(User $user, Project $project): bool
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
