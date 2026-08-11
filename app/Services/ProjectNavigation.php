<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\WorkspaceMembership;

/**
 * The sidebar's project list (partials/app-sidebar).
 *
 * Extracted from ProjectController so every screen that renders the shared sidebar — the
 * projects grid, the Project Workspace tabs — shows the same list without duplicating the
 * visibility rules. Applies the same rule as the projects grid (PRJ-030/031): workspace
 * Owner/Admin see every project; everyone else sees the ones they are a member of, plus
 * public projects unless they are a guest.
 *
 * Must run inside the workspace's tenancy context; the BelongsToTenant scope does the
 * workspace confinement.
 */
class ProjectNavigation
{
    /**
     * The project workspace tab bar, with per-project features resolved (Cycles §3.2.3/§4).
     *
     * The config list is fixed; what varies is whether a feature-gated tab belongs in it at
     * all. A disabled tab is REMOVED rather than shown as "Soon": §3.2.4 asks for it to be
     * hidden or disabled for normal users, and a visible tab that refuses to open is worse
     * than an absent one. Enabling the feature puts it back, functional.
     *
     * Every screen that renders partials/project-tabs goes through here, so a feature can
     * never appear on one project page and not another.
     *
     * @return array<int, array<string, string>>
     */
    public function tabs(Project $project): array
    {
        $gated = ['cycles' => 'cycles'];

        return collect(config('projects.workspace_tabs'))
            ->reject(fn (array $tab) => isset($gated[$tab['key']]) && ! $project->featureEnabled($gated[$tab['key']]))
            ->map(fn (array $tab) => isset($gated[$tab['key']]) ? ['status' => 'active'] + $tab : $tab)
            ->values()->all();
    }

    /**
     * Active projects visible to the user, shaped for the sidebar.
     *
     * @return array<int, array<string, mixed>>
     */
    public function sidebarProjects(User $user): array
    {
        $role = $this->workspaceRole($user);

        $query = Project::query()->where('status', Project::STATUS_ACTIVE)->latest();

        // §38: only Workspace Owner/Admin see every project; everyone else sees exactly the
        // projects they were explicitly added to. Public visibility no longer grants access.
        if (! in_array($role, [WorkspaceMembership::ROLE_OWNER, 'admin'], true)) {
            $query->whereIn('id', ProjectMember::query()->where('user_id', $user->id)->select('project_id'));
        }

        return $query->limit(50)->get()
            ->map(fn (Project $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'emoji' => $p->emoji,
                'url' => route('projects.show', $p->id),
                // Direct workspace URL, so a query string survives (projects.show redirects,
                // which would drop ?create=1).
                'work_items_url' => route('projects.work-items', $p->id),
            ])
            ->all();
    }

    private function workspaceRole(User $user): ?string
    {
        return WorkspaceMembership::query()
            ->where('workspace_id', $user->current_workspace_id)
            ->where('user_id', $user->id)
            ->where('status', WorkspaceMembership::STATUS_ACTIVE)
            ->value('role');
    }
}
