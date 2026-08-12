<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\ProjectView;
use App\Models\User;
use App\Models\WorkItem;

/**
 * Authorizes Views (Views §14, §15).
 *
 * §14 asks for four distinct permissions — open, edit the DATA, edit the CONFIGURATION, and
 * manage — and is emphatic that the middle two are independent: being allowed to change a work
 * item's status through the grid says nothing about being allowed to add a column, and vice
 * versa. That separation is the reason `updateData` and `update` here are not the same method
 * with two names.
 *
 * Slice 1 derives all four from the caller's existing project role (§15's default mapping).
 * Slice 2 adds a `project_view_permissions` table of explicit per-user grants; when it does,
 * each method gains an override lookup ahead of the derivation below and nothing else moves.
 * That is why every method is expressed as a question about this user and this View rather
 * than as an inline role check at the call site.
 *
 * Two rules run through all of it:
 *
 *  - **A View never widens access.** Rows are filtered by WorkItemPolicy exactly as the Work
 *    Items list is, and a cell edit is authorized against the underlying work item. Opening a
 *    View grants sight of nothing the user could not already reach.
 *  - **Private means private (§5.1).** Only the owner may open a private View — including
 *    workspace admins. They may still delete one, because an abandoned private View otherwise
 *    cannot be cleaned up, but deleting is not reading.
 */
class ProjectViewPolicy
{
    /** Can the user open a project's Views screen? Passed with [ProjectView::class, $project]. */
    public function viewAny(User $user, Project $project): bool
    {
        return $project->featureEnabled('views') && $user->can('viewAny', [WorkItem::class, $project]);
    }

    /**
     * §5.1: a project View for anyone permitted on the project; a private View for its owner.
     *
     * The feature gate is deliberately NOT applied here. Feature Disable §5 keeps existing
     * records readable when a feature is switched off — ProjectNavigation marks the tab
     * Disabled and the screen opens read-only — so refusing to read a stored View would lose
     * the configuration the spec (§4.2) promises to preserve.
     */
    public function view(User $user, ProjectView $view): bool
    {
        if (! $user->can('viewAny', [WorkItem::class, $view->project])) {
            return false;
        }

        return ! $view->isPrivate() || $view->isOwnedBy($user);
    }

    /** §14.4 / §15: creating a View needs the ability to add work to the project. */
    public function create(User $user, Project $project): bool
    {
        return $project->featureEnabled('views') && $user->can('create', [WorkItem::class, $project]);
    }

    /**
     * §14.3 — edit the View's CONFIGURATION: columns, widths, the Fixed/Scroll split, the name.
     *
     * The owner, or someone who administers the project. A contributor can edit the data in
     * someone else's shared View without being able to rearrange it for everybody, which is
     * §14.2's "does not automatically allow changing columns" read the other way round.
     */
    public function update(User $user, ProjectView $view): bool
    {
        if (! $view->project->featureEnabled('views') || ! $this->view($user, $view)) {
            return false;
        }

        return $view->isOwnedBy($user) || $user->can('manage', $view->project);
    }

    /**
     * §14.2 — edit the underlying work item DATA through the grid.
     *
     * The bar is "may change work items in this project"; whether this particular row may be
     * changed is asked separately, per row, at the moment of the edit. Note what is NOT here:
     * ownership of the View. A shared project View is a window onto data the user could
     * already edit, and making them the owner to edit through it would be a permission model
     * invented by the grid.
     */
    public function updateData(User $user, ProjectView $view): bool
    {
        return $view->project->featureEnabled('views')
            && $this->view($user, $view)
            && $user->can('create', [WorkItem::class, $view->project]);
    }

    /** §14.4 — rename, duplicate, delete, and later share and publish. */
    public function manage(User $user, ProjectView $view): bool
    {
        return $this->update($user, $view);
    }

    /**
     * §14.4's deletion, which is the one management action that reaches a private View.
     *
     * A private View nobody but its owner can open still occupies a project, and its owner may
     * have left. So a project administrator may remove one without ever being able to read it.
     */
    public function delete(User $user, ProjectView $view): bool
    {
        if (! $view->project->featureEnabled('views')) {
            return false;
        }

        return $view->isOwnedBy($user) || $user->can('manage', $view->project);
    }
}
