<?php

namespace App\Services;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\Backoffice\ClientAccess;
use Illuminate\Support\Collection;

/**
 * May this user enter this workspace? — the one answer the whole application asks
 * (docs/features/workspace-access-control.md).
 *
 * The rule is a JOIN, not a lookup of every workspace that exists:
 *
 *     authenticated user → active workspace membership → allowed workspaces
 *
 * Written as a service rather than left in each caller because it is asked from three
 * different layers that cannot share a base class: the tenancy middleware (before route-model
 * binding, so before any policy can run), the WorkspacePolicy (for `can('view', $workspace)`
 * checks), and the switcher/landing screens that list what somebody may open. One definition
 * means a module added later cannot accidentally invent a looser one.
 *
 * Two gates, and BOTH must pass:
 *   1. an **active** membership row — pending, suspended and removed do not grant access;
 *   2. `ClientAccess`, which is the Back Office's global "disable client" and per-membership
 *      "disable tenant access" switch.
 *
 * Membership states, and where they live:
 *
 * | Requirement | How it is stored |
 * |---|---|
 * | Pending   | a `workspace_invitations` row — **no membership row exists yet** |
 * | Active    | `workspace_memberships.status = 'active'` |
 * | Suspended | `workspace_memberships.status = 'disabled'` (Back Office "Disable Tenant Access") |
 * | Removed   | the membership row is deleted |
 *
 * Pending is deliberately not a membership status: an invitation that has not been accepted
 * grants nothing, and modelling it as a membership would mean every access check had to
 * remember to exclude it. Acceptance is what CREATES the row, already active
 * (WorkspaceInvitationAccepter) — which is Pending → Active, expressed so that forgetting the
 * transition is impossible.
 */
class WorkspaceAccess
{
    /** What somebody is told when they aim at a workspace that is not theirs. */
    public const DENIED_MESSAGE = 'You do not have access to this Workspace.';

    public function __construct(private readonly ClientAccess $clients) {}

    /**
     * The user's ACTIVE membership of this workspace, or null.
     *
     * Read from the central `workspace_memberships` table, so it answers with no tenancy
     * context — which is the whole reason that table is central (CLAUDE.md §18 D6). The
     * middleware needs this answer *before* it may initialize tenancy.
     */
    public function membership(User $user, Workspace|int|string $workspace): ?WorkspaceMembership
    {
        $id = $workspace instanceof Workspace ? $workspace->id : $workspace;

        return WorkspaceMembership::query()
            ->where('workspace_id', $id)
            ->where('user_id', $user->id)
            ->where('status', WorkspaceMembership::STATUS_ACTIVE)
            ->first();
    }

    /** May this user enter this workspace at all? */
    public function allows(User $user, Workspace $workspace): bool
    {
        return $this->membership($user, $workspace) !== null
            && $this->clients->allowsTenant($user, $workspace);
    }

    /**
     * Every workspace this user may enter, by name.
     *
     * The list behind the switcher, the landing router and the middleware's fallback. Anything
     * that enumerates workspaces should come through here rather than query `workspaces`
     * directly — a list built the other way round (all workspaces, then filtered) is one
     * forgotten `where` from being a leak.
     *
     * @return Collection<int, Workspace>
     */
    public function workspacesFor(User $user): Collection
    {
        return $user->workspaces()
            ->orderBy('name')
            ->get()
            ->filter(fn (Workspace $w) => $this->clients->allowsTenant($user, $w))
            ->values();
    }

    /**
     * The workspace this request should run in: the user's current one when they may still
     * enter it, otherwise the first one they can.
     *
     * Returning a DIFFERENT workspace rather than null is the point. `current_workspace_id` is
     * a stored pointer, and being removed from a workspace does not clear it — so without this
     * the pointer alone would keep handing out access to a workspace somebody was thrown out
     * of. Callers persist the switch; this only decides.
     */
    public function resolveCurrent(User $user): ?Workspace
    {
        $current = $user->currentWorkspace;

        if ($current && $this->allows($user, $current)) {
            return $current;
        }

        return $this->workspacesFor($user)->first();
    }

    /** Is this account shut out of the product entirely, rather than out of one workspace? */
    public function blockedGlobally(User $user): bool
    {
        return ! $this->clients->allows($user);
    }
}
