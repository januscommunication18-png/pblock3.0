<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\ProjectPage;
use App\Models\User;
use App\Models\WorkItem;

/**
 * Authorizes project pages (Pages §11).
 *
 * §11 maps onto abilities this app already has rather than a parallel role model: viewing
 * follows "may open this project's work", creating and editing follow "may add work here".
 * Deleting is reserved for the Project Admin, which is ProjectPolicy@manage.
 *
 * Unlike Epics and Modules, reading IS gated on the feature. §5 is explicit that "direct
 * access to disabled Project Pages is restricted", which is the opposite of what the Epic
 * rules ask for — there, disabled records stay readable for historical reference. Pages are
 * documentation rather than a property hanging off a work item, so nothing elsewhere in the
 * app is left dangling by making them unreachable.
 */
class ProjectPagePolicy
{
    /** Can the user open a project's Pages screen? Passed with [ProjectPage::class, $project]. */
    public function viewAny(User $user, Project $project): bool
    {
        return $project->featureEnabled('pages') && $user->can('viewAny', [WorkItem::class, $project]);
    }

    public function view(User $user, ProjectPage $page): bool
    {
        return $this->viewAny($user, $page->project);
    }

    /** §11: Admin and Contributor may create pages; Commenter and Guest may not. */
    public function create(User $user, Project $project): bool
    {
        return $project->featureEnabled('pages') && $user->can('create', [WorkItem::class, $project]);
    }

    /** §11: "edit Pages where permitted" — the same bar as creating one. */
    public function update(User $user, ProjectPage $page): bool
    {
        return $this->create($user, $page->project);
    }

    /** Archive and restore — a Contributor may tidy up documentation they can already edit. */
    public function archive(User $user, ProjectPage $page): bool
    {
        return $this->update($user, $page);
    }

    /** §11 reserves Delete for the Project Admin. */
    public function delete(User $user, ProjectPage $page): bool
    {
        return $page->project->featureEnabled('pages') && $user->can('manage', $page->project);
    }
}
