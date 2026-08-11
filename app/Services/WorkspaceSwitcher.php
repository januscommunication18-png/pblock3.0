<?php

namespace App\Services;

use App\Models\User;

/**
 * The rows behind the "Switch workspace" modal (spec §7 — workspace switcher).
 *
 * Memberships are central rather than tenant-scoped precisely so this list can be built
 * without a tenancy context (CLAUDE.md §18 D6) — which is what lets the switcher render on
 * every screen, including ones that never initialize tenancy.
 *
 * The rendered target is `POST /workspaces/{workspace}/switch`; authorization is re-checked
 * there, so the list is a convenience and never the permission.
 */
class WorkspaceSwitcher
{
    /**
     * Every workspace the user belongs to, current one flagged.
     *
     * @return array<int, array<string, mixed>>
     */
    public function workspacesFor(User $user): array
    {
        $workspaces = $user->workspaces()
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
                'switch_url' => route('workspaces.switch', $w->id),
            ])
            ->all();
    }
}
