<?php

namespace App\Services;

use App\Models\Project;
use App\Models\User;
use App\Models\WorkspaceMembership;

/**
 * Refusing a project, in the one way the whole application refuses it
 * (docs/features/workspace-project-access.md §7).
 *
 * Opening a project has two possible refusals and they say different things:
 *
 *   403 — "You don't have permission to access this project."
 *         You belong to this workspace and the project is PUBLIC, so its existence is already
 *         common knowledge here. Telling you that you may not open it costs nothing and is a
 *         far better answer than pretending it is not there.
 *
 *   404 — silence.
 *         Either the project is PRIVATE, or you do not belong to its workspace at all. In both
 *         cases the name and the existence of the project are themselves confidential
 *         (PRJ-031/032), and a 403 would confirm both. §7 asks for 403 but explicitly allows
 *         404 "where intentionally hiding resource existence is part of the security model" —
 *         which for private projects it is.
 *
 * The choice is here rather than in each controller because it is a security decision, and a
 * security decision restated in eight places is eight chances to restate it wrongly.
 */
class ProjectAccess
{
    public const DENIED_MESSAGE = 'You don\'t have permission to access this project.';

    /**
     * Let this user through, or refuse with whichever answer is honest.
     *
     * Call at the entry to a project PAGE. Sub-resource and media endpoints keep their flat
     * 404: they are not "opening a project", and an API that discriminates its refusals is a
     * probe for the ids behind them.
     */
    public function guardView(User $user, Project $project): void
    {
        if ($user->can('view', $project)) {
            return;
        }

        abort($this->existenceIsOpen($user, $project) ? 403 : 404, self::DENIED_MESSAGE);
    }

    /**
     * Is this project's existence already known to this user?
     *
     * Both halves are required. Workspace membership on its own is not enough — a private
     * project is hidden from the members of its own workspace — and public visibility on its
     * own is not enough either, since "public" means public WITHIN a workspace, never across
     * one.
     */
    private function existenceIsOpen(User $user, Project $project): bool
    {
        if ($project->visibility !== Project::VISIBILITY_PUBLIC) {
            return false;
        }

        return WorkspaceMembership::query()
            ->where('workspace_id', (string) $project->tenant_id)
            ->where('user_id', $user->id)
            ->where('status', WorkspaceMembership::STATUS_ACTIVE)
            ->exists();
    }
}
