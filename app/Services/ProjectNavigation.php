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
    public function __construct(private readonly ProjectFeatureState $featureState) {}

    /**
     * The project workspace tab bar, with per-project features resolved (Cycles §3.2.3/§4).
     *
     * The config list is fixed; what varies is what a feature-gated tab looks like, and
     * Feature Disable §5/§10 gives three answers rather than two:
     *
     *  - **on** — an ordinary tab.
     *  - **off, with records** — the tab STAYS, marked Disabled. Switching a feature off must
     *    never bury its history, and a tab that vanished would leave the only route to those
     *    records being a URL somebody remembered. It opens read-only.
     *  - **off, never used** — removed. There is nothing to preserve, and §5 asks for the
     *    cleaner UI on projects that never touched the feature.
     *
     * Every screen that renders partials/project-tabs goes through here, so a feature can
     * never appear on one project page and not another.
     *
     * @return array<int, array<string, string>>
     */
    public function tabs(Project $project): array
    {
        // tab key => the project feature that decides how it renders.
        $gated = ['cycles' => 'cycles', 'modules' => 'modules', 'epics' => 'epics', 'pages' => 'pages'];

        return collect(config('projects.workspace_tabs'))
            // Resolved once per tab rather than once per question, so a gated tab costs one
            // state lookup instead of two.
            ->map(fn (array $tab) => isset($gated[$tab['key']])
                ? $tab + ['feature_state' => $this->featureState->state($project, $gated[$tab['key']])]
                : $tab)
            // Removed when the feature was never used, and also when the feature declares that
            // being off makes it unreachable rather than read-only (Pages §5) — a tab that
            // opens onto a 404 is worse than no tab.
            ->reject(fn (array $tab) => isset($tab['feature_state'])
                && $tab['feature_state'] !== ProjectFeatureState::ENABLED
                && ($tab['feature_state'] === ProjectFeatureState::DISABLED_UNUSED
                    || config("projects.features.{$gated[$tab['key']]}.hides_when_disabled", false)))
            ->map(function (array $tab) {
                if (! isset($tab['feature_state'])) {
                    return $tab;
                }

                // `status` stays 'active' either way — the page opens. `state` is what tells
                // the tab bar to mark it Disabled.
                return ['status' => 'active',
                    'state' => $tab['feature_state'] === ProjectFeatureState::DISABLED_HISTORY ? 'disabled' : 'enabled',
                ] + $tab;
            })
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
