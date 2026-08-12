<?php

namespace App\Policies;

use App\Models\Cycle;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkItem;

/**
 * Authorizes Cycles (Cycles §12).
 *
 * Every ability starts from the project: a project the user cannot open 404s before any of
 * this runs, and the Cycles feature has to be switched on for the project at all (§3.2.4 —
 * disabling it must take the capability away, not merely hide the tab).
 *
 * The role split reuses the work-item abilities rather than inventing a parallel one, so
 * "who can plan work here" reads the same as "who can create work here" (§12's Contributor
 * row). Deleting a cycle is the exception: §12 reserves it for Project Admin, which is
 * exactly ProjectPolicy@manage.
 */
class CyclePolicy
{
    /** Can the user open a project's Cycles page? Passed with [Cycle::class, $project]. */
    public function viewAny(User $user, Project $project): bool
    {
        // NOT gated on the feature. Disabling Cycles is a configuration change, never a
        // delete: existing records stay readable for historical reference (Feature Disable
        // §1/§9). Every ability that WRITES is gated below; reading is not.
        return $user->can('viewAny', [WorkItem::class, $project]);
    }

    public function view(User $user, Cycle $cycle): bool
    {
        return $this->viewAny($user, $cycle->project);
    }

    /** Create a cycle (§12) — the same people who may add work to the project. */
    public function create(User $user, Project $project): bool
    {
        return $project->featureEnabled('cycles') && $user->can('create', [WorkItem::class, $project]);
    }

    /** Edit a cycle's name, description or dates (§9.1/§9.2). */
    public function update(User $user, Cycle $cycle): bool
    {
        return $this->create($user, $cycle->project);
    }

    /**
     * Add, move, remove or transfer work items (§7.3, §8.3, §10). Same rule as editing the
     * cycle: it changes the cycle's scope, not the project's configuration.
     */
    public function manageWorkItems(User $user, Cycle $cycle): bool
    {
        return $this->update($user, $cycle);
    }

    /** §12 gives Delete to Project Admin only — not to Contributors, unlike Create/Edit. */
    public function delete(User $user, Cycle $cycle): bool
    {
        return $cycle->project->featureEnabled('cycles') && $user->can('manage', $cycle->project);
    }
}
