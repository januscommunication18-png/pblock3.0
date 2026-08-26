<?php

namespace App\Services;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;

/**
 * The rows behind the "Switch workspace" modal (spec §7 — workspace switcher).
 *
 * Memberships are central rather than tenant-scoped precisely so this list can be built
 * without a tenancy context (CLAUDE.md §18 D6) — which is what lets the switcher render on
 * every screen, including ones that never initialize tenancy.
 *
 * The rendered target is `POST /workspaces/{workspace}/switch`; authorization is re-checked
 * there, so the list is a convenience and never the permission.
 *
 * Split into **My Workspaces** and **Invited Workspaces**
 * (docs/features/tenant-workspace-ownership.md §12). The split is presentational only: both
 * groups come from the same active-membership query, so nothing appears here that the user
 * could not already open, and owning the account a workspace sits under never adds one.
 */
class WorkspaceSwitcher
{
    /**
     * Every workspace the user belongs to, current one flagged, in the order they are shown.
     *
     * @return array<int, array<string, mixed>>
     */
    public function workspacesFor(User $user): array
    {
        $workspaces = $user->workspaces()
            ->with('account')
            ->withCount('memberships')
            ->orderBy('name')
            ->get();

        // A user who has never switched has no stored choice; the app treats their first
        // workspace as the active one, so the badge has to agree with that.
        $currentId = $user->current_workspace_id ?? $workspaces->first()?->id;

        return $workspaces
            ->map(fn ($w) => [
                'id' => $w->id,
                'name' => $w->name,
                'slug' => $w->slug,
                'initial' => $w->initial(),
                'role' => ucfirst((string) $w->pivot->role),
                'members' => $w->memberships_count,
                'current' => $w->id === $currentId,
                'owned' => $this->isOwn($user, $w),
                'switch_url' => route('workspaces.switch', $w->id),
            ])
            ->all();
    }

    /**
     * The same rows, in the two groups the switcher shows.
     *
     * A group with nothing in it is dropped rather than rendered empty: somebody who has only
     * ever been invited should not be shown a "My Workspaces" heading with a blank space under
     * it, and somebody who has never been invited should not be told about invitations.
     *
     * @return array<int, array{key: string, label: string, workspaces: array<int, array<string, mixed>>}>
     */
    public function groupsFor(User $user): array
    {
        $rows = $this->workspacesFor($user);

        $groups = [
            ['key' => 'mine', 'label' => 'My Workspaces', 'workspaces' => array_values(
                array_filter($rows, fn (array $row) => $row['owned']),
            )],
            ['key' => 'invited', 'label' => 'Invited Workspaces', 'workspaces' => array_values(
                array_filter($rows, fn (array $row) => ! $row['owned']),
            )],
        ];

        return array_values(array_filter($groups, fn (array $g) => $g['workspaces'] !== []));
    }

    /**
     * Is this one of the user's OWN workspaces? (§12)
     *
     * Two ways to qualify, both of which the requirement names: the workspace sits under an
     * account this user owns, or their membership role in it is Owner. The second is not
     * redundant — a workspace can be handed over by making somebody Owner of it without the
     * account beneath it changing hands, and to that person it is theirs.
     */
    private function isOwn(User $user, Workspace $workspace): bool
    {
        return $workspace->account?->isOwnedBy($user)
            || (string) $workspace->pivot->role === WorkspaceMembership::ROLE_OWNER;
    }
}
