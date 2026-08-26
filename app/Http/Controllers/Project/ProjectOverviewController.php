<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\WorkItem;
use App\Services\ProjectAccess;
use App\Services\ProjectNavigation;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Project → Overview (Project Overview §1).
 *
 * Read-only. Everything here already has somewhere it is edited — the cover and the name in
 * Project settings, the state and dates on the project record — and a second set of controls
 * for the same fields is a second place for them to disagree. This screen answers "what is
 * this project and how is it going", nothing more.
 *
 * The Overview | Milestones segment at the top shows Milestones only when the feature is on,
 * decided by ProjectNavigation so the segment and the tab bar can never disagree about it.
 */
class ProjectOverviewController extends Controller
{
    public function __construct(private readonly ProjectNavigation $navigation) {}

    /** GET /projects/{project}/overview */
    public function show(Project $project): View
    {
        return $this->render($project, 'overview');
    }

    /**
     * GET /projects/{project}/milestones
     *
     * A placeholder for now, and reachable only while the feature is on: the route 404s when
     * it is off rather than rendering an empty screen, so the segment and the URL agree.
     */
    public function milestones(Project $project): View
    {
        abort_unless($project->featureEnabled('milestones'), 404);

        return $this->render($project, 'milestones');
    }

    private function render(Project $project, string $segment): View
    {
        // 404, never leak whether an inaccessible project exists (spec §12).
        // The project PAGE: refused as §7 asks — 403 with a message where the project's
        // existence is already open to this user, 404 where it is not.
        app(ProjectAccess::class)->guardView(Auth::user(), $project);

        $project->loadMissing(['lead', 'state', 'priority']);

        return view('projects.overview', [
            'workspace' => Auth::user()->currentWorkspace,
            'user' => Auth::user(),
            'project' => $project,
            'segment' => $segment,
            'hasMilestones' => $project->featureEnabled('milestones'),
            'progress' => $this->progress($project),
            'properties' => $this->properties($project),
            'tabs' => $this->navigation->tabs($project),
            'activeTab' => 'overview',
            'projects' => $this->navigation->sidebarProjects(Auth::user()),
            'canCreateProject' => Auth::user()->can('create', [WorkItem::class, $project]),
            'canManage' => Auth::user()->can('manage', $project),
        ]);
    }

    /**
     * Work items by state GROUP, with counts and percentages.
     *
     * Grouped by `group` rather than by state: a project may rename Todo or add a second
     * started state, and the bar is about how far along the work is, not how many names the
     * project has for it. The five groups are always present, in order, so an empty project
     * still renders a legible breakdown instead of a blank strip.
     *
     * @return array<string, mixed>
     */
    private function progress(Project $project): array
    {
        $counts = WorkItem::query()
            ->where('work_items.project_id', $project->id)
            ->active()
            ->join('project_item_states', 'project_item_states.id', '=', 'work_items.state_id')
            ->groupBy('project_item_states.group')
            ->select('project_item_states.group', DB::raw('count(*) as total'))
            ->pluck('total', 'group');

        $total = (int) $counts->sum();

        // The groups and their colours come from the same seed list the project's own states
        // were created from, so the bar matches the dots in the Work Items list without a
        // second palette to keep in step.
        $colors = collect(config('projects.default_item_states'))->pluck('color', 'group');

        $groups = $colors->keys()
            ->map(function (string $group) use ($counts, $total, $colors) {
                $count = (int) ($counts[$group] ?? 0);

                return [
                    'group' => $group,
                    'label' => ucfirst($group),
                    'color' => $colors[$group],
                    'count' => $count,
                    // Rounded for display only. The BAR is drawn from the raw fraction, so
                    // five rounded percentages that sum to 101 cannot push it past its box.
                    'percent' => $total > 0 ? (int) round($count / $total * 100) : 0,
                    'fraction' => $total > 0 ? $count / $total : 0.0,
                ];
            })
            ->values()
            ->all();

        return ['total' => $total, 'groups' => $groups];
    }

    /**
     * The right-hand Properties panel.
     *
     * Every row reads a column that already exists on the project; nothing here is derived or
     * invented, so what the panel shows is what Project settings would show.
     *
     * @return array<int, array<string, mixed>>
     */
    private function properties(Project $project): array
    {
        $members = $project->members()->count();
        $labels = $project->labels()->whereNull('archived_at')->count();

        return [
            ['icon' => 'circle-info', 'label' => 'State', 'value' => $project->state?->name ?? 'None'],
            ['icon' => 'bars', 'label' => 'Priority', 'value' => $project->priority?->name ?? 'None'],
            ['icon' => 'user', 'label' => 'Lead', 'value' => $project->lead?->displayName() ?? 'Unassigned'],
            ['icon' => 'users', 'label' => 'Members', 'value' => $members.' member'.($members === 1 ? '' : 's')],
            ['icon' => 'tag', 'label' => 'Labels', 'value' => $labels.' label'.($labels === 1 ? '' : 's')],
            ['icon' => 'calendar', 'label' => 'Start date', 'value' => $project->start_date?->format('j M Y') ?? 'None'],
            ['icon' => 'calendar', 'label' => 'Due date', 'value' => $project->end_date?->format('j M Y') ?? 'None'],
        ];
    }
}
