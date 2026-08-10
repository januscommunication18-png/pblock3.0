<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\WorkspaceMembership;
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

        // No workspace yet -> send through first-workspace onboarding.
        if (! $user->workspaces()->exists()) {
            return redirect()->route('onboarding.workspace');
        }

        $current = $user->currentWorkspace ?? $user->workspaces()->first();

        // Workspaces for the switcher modal, with the user's role and member counts.
        $workspaces = $user->workspaces()
            ->withCount('memberships')
            ->orderBy('name')
            ->get()
            ->map(fn ($w) => [
                'id' => $w->id,
                'name' => $w->name,
                'slug' => $w->slug,
                'initial' => $w->initial(),
                'role' => ucfirst((string) $w->pivot->role),
                'members' => $w->memberships_count,
                'current' => $current && $w->id === $current->id,
                'switch_url' => route('workspaces.switch', $w->id),
            ])
            ->all();

        return view('app.welcome', [
            'user' => $user,
            'workspace' => $current,
            'workspaces' => $workspaces,
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

