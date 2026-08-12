<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\WorkItem;
use App\Services\ProjectNavigation;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;

/**
 * Project Workspace shell (Phase 5, requirements §3).
 *
 * Renders the Coming Soon page for the remaining non-MVP tabs — Overview, Modules, Views and
 * Pages. They stay visible so the planned information architecture is legible, but must never
 * expose unfinished functionality as if it were production-ready (spec §13).
 *
 * Cycles and Modules are no longer among them: each has its own controller and routes,
 * registered ahead of this catch-all.
 */
class ProjectWorkspaceController extends Controller
{
    public function __construct(private readonly ProjectNavigation $navigation) {}

    /** GET /projects/{project}/{tab} for every tab except work-items. */
    public function tab(Project $project, string $tab): View
    {
        // 404, never leak whether an inaccessible project exists (spec §12).
        abort_unless(Auth::user()->can('viewAny', [WorkItem::class, $project]), 404);

        // The Coming Soon check reads the raw config: a feature-gated tab is owned by its own
        // controller and routed before this catch-all, so it must never resolve here.
        $current = collect(config('projects.workspace_tabs'))->firstWhere('key', $tab);
        abort_if($current === null || ($current['status'] ?? '') === 'active', 404);
        abort_if(in_array($tab, ['cycles', 'modules'], true), 404);

        return view('projects.coming-soon', [
            'workspace' => Auth::user()->currentWorkspace,
            'user' => Auth::user(),
            'project' => $project,
            'tabs' => $this->navigation->tabs($project),
            'activeTab' => $tab,
            'label' => $current['label'],
            // Shared sidebar: project list + the gate on its "New work item" action.
            'projects' => $this->navigation->sidebarProjects(Auth::user()),
            'canCreateProject' => Auth::user()->can('create', [WorkItem::class, $project]),
            // Gates Settings in the header's ⋯ menu (the settings screen re-checks it).
            'canManage' => Auth::user()->can('manage', $project),
        ]);
    }
}
