<?php

namespace App\Policies;

use App\Models\Epic;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkItem;

/**
 * Authorizes Epics (Epic §15).
 *
 * Every ability starts from the project: a project the user cannot open 404s before any of
 * this runs.
 *
 * §15's matrix maps onto abilities this app already has rather than a parallel role model:
 * Create/Edit/Assign follow the work-item abilities, so "who may organise work here" reads the
 * same as "who may create work here". Delete is the exception — §15 reserves it for Admin,
 * "No by default" for Contributor, which is exactly ProjectPolicy@manage.
 *
 * READING IS NOT GATED ON THE FEATURE, and that is the important line here. Disabling Epics is
 * a configuration change, never a delete: existing epics stay readable for historical
 * reference, and a work item keeps showing the epic it was filed under. Every ability that
 * WRITES is gated; every ability that only reads is not. Cycles and Modules hide themselves
 * completely when switched off — Epic deliberately does not.
 */
class EpicPolicy
{
    /**
     * Can the user open a project's Epics page? Passed with [Epic::class, $project].
     *
     * No feature check: existing epics stay accessible for historical reference even while the
     * feature is off. What they get is a read-only page — see EpicController::screen(), which
     * sends `featureEnabled` so the screen can say so.
     */
    public function viewAny(User $user, Project $project): bool
    {
        return $user->can('viewAny', [WorkItem::class, $project]);
    }

    public function view(User $user, Epic $epic): bool
    {
        return $this->viewAny($user, $epic->project);
    }

    /**
     * Create an epic (§15) — the same people who may add work to the project, and only while
     * the feature is on.
     *
     * Update, archive and manageWorkItems all defer to this, so switching Epics off makes the
     * whole feature read-only in one place rather than in five.
     */
    public function create(User $user, Project $project): bool
    {
        return $project->featureEnabled('epics') && $user->can('create', [WorkItem::class, $project]);
    }

    /** Edit title, description, status, priority, dates, lead and members (§6/§7). */
    public function update(User $user, Epic $epic): bool
    {
        return $this->create($user, $epic->project);
    }

    /**
     * Add or remove work items (§9). The same rule as editing: it changes what the epic
     * contains, not the project's configuration.
     */
    public function manageWorkItems(User $user, Epic $epic): bool
    {
        return $this->update($user, $epic);
    }

    /** Archive and restore (§16) — a Contributor may tidy up their own body of work. */
    public function archive(User $user, Epic $epic): bool
    {
        return $this->update($user, $epic);
    }

    /** §15 gives Delete to Admin only — not to Contributors, unlike Create/Edit. */
    public function delete(User $user, Epic $epic): bool
    {
        return $epic->project->featureEnabled('epics') && $user->can('manage', $epic->project);
    }
}
