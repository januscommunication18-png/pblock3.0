<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Http\Requests\Project\StoreViewColumnRequest;
use App\Http\Requests\Project\UpdateViewColumnRequest;
use App\Models\Project;
use App\Models\ProjectView;
use App\Models\ProjectViewColumn;
use App\Services\ViewColumnLayout;
use App\Services\ViewFieldCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * A View's columns (Views §8, §9, §10).
 *
 * Every action answers with the WHOLE column set rather than the one row that changed. A View's
 * columns are a layout, not a list of independent records: adding one changes what the field
 * selector may still offer, and a drag changes the order of everything around it. Returning the
 * layout means the client renders what the server actually stored instead of predicting it.
 */
class ProjectViewColumnController extends Controller
{
    public function __construct(
        private readonly ViewColumnLayout $layout,
        private readonly ViewFieldCatalog $catalog,
    ) {}

    /** GET /projects/{project}/views/{view}/fields — the Add Column selector (§9). */
    public function fields(Project $project, ProjectView $view): JsonResponse
    {
        $this->guard($project, $view, 'view');

        return response()->json([
            'ok' => true,
            'fields' => $this->catalog->selector($project, $view->columns->map->key()->all()),
        ]);
    }

    /** POST /projects/{project}/views/{view}/columns (§9). */
    public function store(StoreViewColumnRequest $request, Project $project, ProjectView $view): JsonResponse
    {
        abort_unless((int) $view->project_id === (int) $project->id, 404);

        $this->layout->add(
            $view,
            (string) $request->input('key'),
            (string) $request->input('position', ProjectViewColumn::POSITION_SCROLL),
        );

        return $this->payload($project, $view, 'Column added.');
    }

    /** PATCH /projects/{project}/views/{view}/columns/{column} — §10's settings. */
    public function update(UpdateViewColumnRequest $request, Project $project, ProjectView $view, ProjectViewColumn $column): JsonResponse
    {
        abort_unless((int) $view->project_id === (int) $project->id, 404);

        $column->fill($request->validated())->save();

        // No toast: this fires on every drag of a column edge, and a message per pixel-drag
        // would bury the screen. The grid showing the new width is the feedback.
        return $this->payload($project, $view);
    }

    /** DELETE /projects/{project}/views/{view}/columns/{column} (§8.1/§8.2). */
    public function destroy(Project $project, ProjectView $view, ProjectViewColumn $column): JsonResponse
    {
        $this->guard($project, $view, 'update');
        abort_unless((int) $column->project_view_id === (int) $view->id, 404);

        $column->delete();

        return $this->payload($project, $view, 'Column removed.');
    }

    /**
     * PUT /projects/{project}/views/{view}/columns/order — the whole arrangement (§8.3).
     *
     * One request for both cards, because a drag between them changes the moved column's
     * position type AND the order of everything around it in both. Sent as several requests,
     * a single failure would leave a layout that is half-applied and has no correct reading.
     */
    public function order(Request $request, Project $project, ProjectView $view): JsonResponse
    {
        $this->guard($project, $view, 'update');

        $validated = $request->validate([
            'fixed' => ['present', 'array'],
            'fixed.*' => ['integer'],
            'scroll' => ['present', 'array'],
            'scroll.*' => ['integer'],
        ]);

        $this->layout->reorder($view, [
            ProjectViewColumn::POSITION_FIXED => $validated['fixed'],
            ProjectViewColumn::POSITION_SCROLL => $validated['scroll'],
        ]);

        return $this->payload($project, $view);
    }

    private function payload(Project $project, ProjectView $view, ?string $message = null): JsonResponse
    {
        // Reloaded rather than reused: `columns` was eager-loaded before the write, so the
        // relation still holds the old arrangement and would answer with what the client
        // already had.
        $view = $view->fresh(['columns']);

        return response()->json(array_filter([
            'ok' => true,
            'columns' => $this->layout->describe($view, $project),
            'frozen' => $this->layout->frozenCount($view),
            // What the selector may still offer changes with every add and remove (§8.3).
            'fields' => $this->catalog->selector($project, $view->columns->map->key()->all()),
            'message' => $message,
        ], fn ($v) => $v !== null));
    }

    private function guard(Project $project, ProjectView $view, string $ability): void
    {
        abort_unless((int) $view->project_id === (int) $project->id, 404);
        abort_unless(Auth::user()->can($ability, $view), $ability === 'view' ? 404 : 403);
    }
}
