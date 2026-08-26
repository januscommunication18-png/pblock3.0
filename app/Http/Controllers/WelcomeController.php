<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\WorkspaceMembership;
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
     * Active projects visible to the user for the sidebar. Runs inside the workspace's
     * tenancy context so the tenant-scoped Project query is confined to it (this route is
     * not behind the workspace.tenancy middleware).
     *
     * @return array<int, array<string, mixed>>
     */
    private function sidebarProjects($workspace, $user): array
    {
        if (! $workspace) {
            return [];
        }

        return $workspace->run(function () use ($workspace, $user) {
            $role = WorkspaceMembership::query()
                ->where('workspace_id', $workspace->id)->where('user_id', $user->id)
                ->where('status', WorkspaceMembership::STATUS_ACTIVE)->value('role');

            $query = Project::query()->where('status', Project::STATUS_ACTIVE)->latest();

            if (! in_array($role, ['owner', 'admin'], true)) {
                $memberIds = ProjectMember::query()->where('user_id', $user->id)->pluck('project_id');
                $query->where(function ($q) use ($memberIds, $role) {
                    $q->whereIn('id', $memberIds);
                    if (in_array($role, ['member', 'viewer'], true)) {
                        $q->orWhere('visibility', 'public');
                    }
                });
            }

            return $query->limit(50)->get()
                ->map(fn (Project $p) => ['name' => $p->name, 'emoji' => $p->emoji, 'url' => route('projects.show', $p->id)])
                ->all();
        });
    }
}
