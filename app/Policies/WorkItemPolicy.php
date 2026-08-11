<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkspaceMembership;

/**
 * Authorizes Work Items (Phase 5, requirements §7).
 *
 * Two layers, both enforced server-side and never only hidden in the UI:
 *
 * 1. **Reach** — a user may only touch work items of a project they can open, so every
 *    ability defers to ProjectPolicy@view. A project they cannot see 404s before this runs.
 * 2. **Read-only vs. contributor** — workspace Owner/Admin/Member may create and mutate;
 *    Viewer and Guest may open the list and detail but cannot change anything. This mirrors
 *    ProjectPolicy@create's role split, so "who can add work" reads the same everywhere.
 */
class WorkItemPolicy
{
    /** Can the user open a project's Work Items list? */
    public function viewAny(User $user, Project $project): bool
    {
        return $user->can('view', $project);
    }

    /** Can the user open this specific work item? */
    public function view(User $user, WorkItem $item): bool
    {
        return $user->can('view', $item->project);
    }

    /**
     * Can the user add a work item to this project?
     * Passed as `$user->can('create', [WorkItem::class, $project])`.
     */
    public function create(User $user, Project $project): bool
    {
        return $user->can('view', $project) && $this->isContributor($user, $project);
    }

    /** Can the user change this work item? Same rule as creating one. */
    public function update(User $user, WorkItem $item): bool
    {
        return $this->create($user, $item->project);
    }

    /**
     * Can the user delete this work item? The §34 matrix gives Delete to the same roles as
     * Edit — Admin and Contributor — so this mirrors update rather than being Admin-only.
     */
    public function delete(User $user, WorkItem $item): bool
    {
        return $this->update($user, $item);
    }

    /**
     * May this user create/edit work items here?
     *
     * Layer 2 of §16: the answer comes from the user's **project** role, per the §34 matrix
     * — Admin and Contributor may, Commenter and Guest may not. Workspace Owner/Admin keep
     * administrative access across every project (§18), so they qualify without a project
     * membership row.
     */
    private function isContributor(User $user, Project $project): bool
    {
        $workspaceRole = WorkspaceMembership::query()
            ->where('workspace_id', $project->tenant_id)
            ->where('user_id', $user->id)
            ->where('status', WorkspaceMembership::STATUS_ACTIVE)
            ->value('role');

        if (in_array($workspaceRole, [WorkspaceMembership::ROLE_OWNER, 'admin'], true)) {
            return true;
        }

        return ProjectMember::contributes(ProjectMember::roleFor($user->id, $project->id));
    }
}
