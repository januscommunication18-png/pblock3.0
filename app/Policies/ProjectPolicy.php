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
     * Can the user create a project in this workspace?
     *
     * | Owner | Admin | Manager | Member | Viewer | Guest |
     * |---|---|---|---|---|---|
     * | yes | yes | `config('projects.manager_can_create')` | no | no | no |
     *
     * **Member is NO** (docs/features/workspace-project-access.md §3). It used to be yes, which
     * put "+ Add Project" in front of everybody invited into somebody else's workspace — and,
     * because this policy is what `store()` checks too, actually let them create one. Creating
     * a project is an act of shaping a workspace, and a Member is somebody invited to work
     * inside one, not to arrange it.
     *
     * Manager is the configurable row the requirement asks for, and the reason it is not simply
     * "yes": a Manager carries no workspace-level privileges of its own here — per Project
     * Member Management §17 it only bites when the same person is also that project's Admin —
     * so whether it extends to creating projects is a product decision, not a fact about the
     * role. It defaults to allowed, since a manager who cannot start a project has little left
     * to manage.
     *
     * Passed as `$user->can('create', [Project::class, $workspace])`, which is also what every
     * screen's `+ Add Project` button reads — so the button and the endpoint cannot disagree.
     */
    public function create(User $user, Workspace $workspace): bool
    {
        $role = $this->workspaceRole($user, $workspace);

        if (in_array($role, [WorkspaceMembership::ROLE_OWNER, 'admin'], true)) {
            return true;
        }

        return $role === 'manager' && (bool) config('projects.manager_can_create', true);
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
        //
        // NOTE: the General settings spec §8 says a PUBLIC project should instead be
        // reachable by any workspace member. That contradicts Project Member Management §38,
        // which this implements deliberately, so it is left alone pending a decision — see
        // the open question in docs/features/project-settings-general.md.
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
