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

    /**
     * Can the user open this specific work item?
     *
     * Reach first, then §10's Work Item View: a project set to "assigned work items only"
     * shows an ordinary member just the items they are an assignee of. Enforced here rather
     * than by hiding rows, so a hand-typed URL or a direct API call is refused too (§10
     * Security Requirement) — 404 at the controller, which never confirms the item exists.
     */
    public function view(User $user, WorkItem $item): bool
    {
        if (! $user->can('view', $item->project)) {
            return false;
        }

        return $this->canSeeEveryItem($user, $item->project) || $this->isAssignee($user, $item);
    }

    /**
     * Who keeps sight of every work item regardless of the setting (§10): workspace
     * owners/admins, this project's admins, and the project lead — the people who have to
     * administer the project rather than only work in it.
     */
    public function canSeeEveryItem(User $user, Project $project): bool
    {
        if (! $project->restrictsToAssigned()) {
            return true;
        }

        if ((int) $project->lead_user_id === (int) $user->id) {
            return true;
        }

        if ($this->administersWorkspace($user, $project->tenant_id)) {
            return true;
        }

        return ProjectMember::query()
            ->where('project_id', $project->id)
            ->where('user_id', $user->id)
            ->where('role', 'admin')
            ->exists();
    }

    private function isAssignee(User $user, WorkItem $item): bool
    {
        return $item->assignees()->whereKey($user->id)->exists();
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
     * ---- Drafts (docs/features/drafts.md §"User Roles") ----------------------------------
     *
     * A draft has no project, so none of the abilities above apply to one: they all start from
     * ProjectPolicy@view and a draft has nothing to view. These five answer the two questions
     * a draft actually raises — may this user keep drafts at all, and is this draft theirs.
     */

    /**
     * May the user keep drafts in the active workspace?
     *
     * Everyone except Viewer and Guest, who cannot create work items anywhere (§7) — for them
     * a draft would be a note they could never publish, so the screen is refused outright
     * rather than offered as a dead end (D-D6).
     */
    public function createDraft(User $user): bool
    {
        return in_array($this->workspaceRole($user, (string) $user->current_workspace_id), [
            WorkspaceMembership::ROLE_OWNER, 'admin', 'manager', 'member',
        ], true);
    }

    /**
     * May the user open this draft? Only its author — drafts are private (§4), and that holds
     * against workspace Owners and Admins too. The controller 404s rather than 403s, so a
     * refusal never confirms the draft exists.
     */
    public function viewDraft(User $user, WorkItem $draft): bool
    {
        return $draft->isDraft()
            && (int) $draft->created_by === (int) $user->id
            && $this->createDraft($user);
    }

    public function updateDraft(User $user, WorkItem $draft): bool
    {
        return $this->viewDraft($user, $draft);
    }

    public function deleteDraft(User $user, WorkItem $draft): bool
    {
        return $this->viewDraft($user, $draft);
    }

    /**
     * May the user turn this draft into a work item in this project?
     *
     * Both halves: it must be their draft, and the project must be one they could have created
     * the item in directly. Publishing is not a way around §16.
     */
    public function publishDraft(User $user, WorkItem $draft, Project $project): bool
    {
        return $this->viewDraft($user, $draft) && $this->create($user, $project);
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
        if ($this->administersWorkspace($user, $project->tenant_id)) {
            return true;
        }

        return ProjectMember::contributes(ProjectMember::roleFor($user->id, $project->id));
    }

    /** Workspace Owner/Admin keep administrative access across every project (§18). */
    private function administersWorkspace(User $user, string $workspaceId): bool
    {
        return in_array($this->workspaceRole($user, $workspaceId), [
            WorkspaceMembership::ROLE_OWNER, 'admin',
        ], true);
    }

    private function workspaceRole(User $user, string $workspaceId): ?string
    {
        return WorkspaceMembership::query()
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $user->id)
            ->where('status', WorkspaceMembership::STATUS_ACTIVE)
            ->value('role');
    }
}
