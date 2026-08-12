<?php

namespace App\Policies;

use App\Models\Module;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkItem;

/**
 * Authorizes Modules (Module Management §14).
 *
 * Every ability starts from the project: a project the user cannot open 404s before any of
 * this runs, and Modules has to be switched on for the project at all (§4.2 — disabling it
 * takes the capability away, not just the tab).
 *
 * The role split reuses the work-item abilities rather than inventing a parallel one, so
 * "who can organise work here" reads the same as "who can create work here" (§14's
 * Contributor column). Delete is the exception: §14 reserves it for Admin, which is exactly
 * ProjectPolicy@manage.
 */
class ModulePolicy
{
    /** Can the user open a project's Modules page? Passed with [Module::class, $project]. */
    public function viewAny(User $user, Project $project): bool
    {
        // NOT gated on the feature. Disabling Modules is a configuration change, never a
        // delete: existing records stay readable for historical reference (Feature Disable
        // §1/§9). Every ability that WRITES is gated below; reading is not.
        return $user->can('viewAny', [WorkItem::class, $project]);
    }

    public function view(User $user, Module $module): bool
    {
        return $this->viewAny($user, $module->project);
    }

    /** Create a module (§14) — the same people who may add work to the project. */
    public function create(User $user, Project $project): bool
    {
        return $project->featureEnabled('modules') && $user->can('create', [WorkItem::class, $project]);
    }

    /** Edit title, description, status, dates, lead and members (§11). */
    public function update(User $user, Module $module): bool
    {
        return $this->create($user, $module->project);
    }

    /**
     * Add or remove work items (§8.3). Same rule as editing: it changes what the module
     * contains, not the project's configuration.
     */
    public function manageWorkItems(User $user, Module $module): bool
    {
        return $this->update($user, $module);
    }

    /** Archive and restore (§12) — a Contributor may tidy up their own body of work. */
    public function archive(User $user, Module $module): bool
    {
        return $this->update($user, $module);
    }

    /** §14 gives Delete to Admin only — not to Contributors, unlike Create/Edit. */
    public function delete(User $user, Module $module): bool
    {
        return $module->project->featureEnabled('modules') && $user->can('manage', $module->project);
    }
}
