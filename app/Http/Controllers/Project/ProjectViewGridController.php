<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Http\Requests\Project\UpdateViewCellRequest;
use App\Models\Project;
use App\Models\ProjectView;
use App\Models\WorkItem;
use App\Services\ViewGridPayload;
use App\Services\WorkItemUpdater;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * A View's data: the rows, and inline edits to them (Views §7, §11, §24).
 *
 * Separate from the configuration controller because these are the two halves §14 insists on
 * keeping apart — changing what the grid SHOWS and changing what it shows the data OF are
 * different permissions, and they are different endpoints here for the same reason.
 */
class ProjectViewGridController extends Controller
{
    public function __construct(
        private readonly ViewGridPayload $grid,
        private readonly WorkItemUpdater $updater,
    ) {}

    /**
     * GET /projects/{project}/views/{view}/rows — one page of rows (§24).
     *
     * The grid asks for the next page as the user scrolls, so a large project never lands in
     * the browser all at once. Search and sort are parameters rather than client-side work for
     * the same reason: filtering rows you had to download first saves nothing.
     */
    public function rows(Request $request, Project $project, ProjectView $view): JsonResponse
    {
        $this->guard($project, $view);

        $payload = $this->grid->rows(
            $view,
            $project,
            (int) $request->integer('page', 1),
            $request->string('q')->toString(),
            $request->string('sort')->toString(),
            $request->string('dir')->toString(),
        );

        return response()->json(['ok' => true] + $payload);
    }

    /**
     * PATCH /projects/{project}/views/{view}/rows/{workItem} — an inline cell edit (§11).
     *
     * All six of §11.3's checks have already run: the first four in the form request's
     * `authorize()`, which refuses before any rule is evaluated, and the last two in its
     * after-hook. What is left here is the write itself — and it goes through the ordinary
     * `WorkItemUpdater`, so §11.4's "record the change in normal Work Item activity" is not
     * something this endpoint has to remember to do. There is no second write path.
     */
    public function updateCell(UpdateViewCellRequest $request, Project $project, ProjectView $view, WorkItem $workItem): JsonResponse
    {
        $data = $request->validated();
        unset($data['column_id']);

        $item = $this->updater->update($workItem, Auth::user(), $data);

        // The whole row goes back, not just the field: a state change can move a work item's
        // blocked count or its parent chip, and §11.4's "revert the cell if the server rejects"
        // needs the client to be replacing a row with the truth rather than patching one value.
        // Built through the grid payload so a View showing Epic status or Cycle dates gets
        // those back too — a bare card carries none of them.
        return response()->json([
            'ok' => true,
            'row' => $this->grid->row($view, $item->fresh()),
            'message' => 'Saved.',
        ]);
    }

    private function guard(Project $project, ProjectView $view): void
    {
        abort_unless((int) $view->project_id === (int) $project->id, 404);
        abort_unless(Auth::user()->can('view', $view), 404);
    }
}
