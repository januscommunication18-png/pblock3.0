<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProjectNavigation;
use App\Services\WorkspaceAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Post-onboarding "Get started" home / app shell (spec §3, welcome.html POC).
 * Successful create / invite / skip flows all land here.
 */
class WelcomeController extends Controller
{
    /** GET /welcome */
    public function show(): View|RedirectResponse
    {
        $user = Auth::user();

        /*
         * Which workspace this screen is ABOUT is an access decision, so it comes from
         * WorkspaceAccess like everywhere else (docs/features/workspace-access-control.md).
         * This route runs outside `workspace.tenancy` — a user with no workspace has to be able
         * to reach it — so the middleware's check does not cover it, and reading
         * `current_workspace_id` directly would render the get-started home, sidebar projects
         * and all, for a workspace the user had been removed from.
         */
        $current = app(WorkspaceAccess::class)->resolveCurrent($user);

        // Nothing they may open -> send through first-workspace onboarding.
        if (! $current) {
            return redirect()->route('onboarding.workspace');
        }

        if ((string) $user->current_workspace_id !== (string) $current->id) {
            $user->forceFill(['current_workspace_id' => $current->id])->save();
        }

        // The switcher's own data comes from the view composer on
        // partials.workspace-switcher, which every screen shares — nothing to pass here.
        return view('app.welcome', [
            'user' => $user,
            'workspace' => $current,
            'projects' => $this->sidebarProjects($current, $user),
            'canCreateProject' => $user->can('create', [Project::class, $current]),
        ]);
    }

    /**
     * The projects the sidebar may offer — ProjectNavigation's list, not one of our own.
     *
     * This method used to hold a SECOND definition of "which projects can this person see", and
     * that copy still granted access by `visibility`: a public project was listed for every
     * Member. `ProjectPolicy::view()` stopped accepting visibility as access when Project Member
     * Management §38 landed, so the sidebar went on offering projects the policy then refused —
     * a link straight to 404 for anybody invited into somebody else's workspace
     * (docs/features/workspace-project-access.md §1).
     *
     * Runs inside the workspace's tenancy context because this route is NOT behind the
     * `workspace.tenancy` middleware — a user with no workspace at all has to be able to reach
     * it — and `Project` / `ProjectMember` are tenant-scoped.
     *
     * @return array<int, array<string, mixed>>
     */
    private function sidebarProjects(?Workspace $workspace, User $user): array
    {
        if (! $workspace) {
            return [];
        }

        return $workspace->run(fn () => app(ProjectNavigation::class)->sidebarProjects($user));
    }
}
