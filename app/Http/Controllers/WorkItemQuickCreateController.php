<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\WorkItem;
use App\Services\ProjectItemStateProvisioner;
use App\Services\ProjectNavigation;
use App\Services\WorkItemScreenPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Data for the "New work item" modal that opens ANYWHERE (docs/features/quick-create.md).
 *
 * The sidebar's button used to navigate to a project's Work Items and auto-open the modal
 * there. That works, but it answers a question nobody asked — "which screen am I on?" — by
 * taking you off it. Opening in place needs two things the current screen has no reason to
 * know: which projects you may add work to, and that project's own vocabulary.
 *
 * Both are fetched only when the modal opens. Rendering them into every page would put a
 * per-project policy check and a states query on every request in the application, to serve a
 * button most page views never press.
 */
class WorkItemQuickCreateController extends Controller
{
    public function __construct(
        private readonly ProjectNavigation $navigation,
        private readonly WorkItemScreenPayload $payload,
        private readonly ProjectItemStateProvisioner $states,
    ) {}

    /**
     * GET /work-items/create-options — the projects this person may add work to.
     *
     * The same question `WorkItemPolicy@create` answers on a project's own screen, asked once
     * per candidate. The store endpoint re-checks it, so this list is the convenience and not
     * the guard.
     */
    public function projects(): JsonResponse
    {
        $user = Auth::user();

        $projects = $this->navigation->visible($user)->get()
            ->filter(fn (Project $project) => $user->can('create', [WorkItem::class, $project]))
            ->map(fn (Project $project) => [
                'id' => $project->id,
                'name' => $project->name,
                'identifier' => $project->identifier,
            ])
            ->values()
            ->all();

        return response()->json(['ok' => true, 'projects' => $projects]);
    }

    /**
     * GET /projects/{project}/work-items/options — that project's pickers.
     *
     * `pickerOptions` is the same method the Views grid uses for its cell editors, so the
     * quick-create modal offers exactly what the project's own create modal offers. A second
     * assembly here would be a second definition of "what may this work item be".
     */
    public function options(Project $project): JsonResponse
    {
        abort_unless(Auth::user()->can('create', [WorkItem::class, $project]), 404);

        // States are provisioned on first use, and this may well BE the first use — a project
        // nobody has opened yet would otherwise offer an empty status picker.
        $this->states->for($project);

        return response()->json([
            'ok' => true,
            'options' => $this->payload->pickerOptions($project),
            'store' => route('projects.work-items.store', $project),
        ]);
    }
}
