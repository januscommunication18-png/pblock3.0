<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Http\Requests\Project\StoreProjectViewRequest;
use App\Http\Requests\Project\UpdateProjectViewRequest;
use App\Models\Project;
use App\Models\ProjectView;
use App\Models\ProjectViewColumn;
use App\Models\ProjectViewFavorite;
use App\Models\User;
use App\Models\WorkItem;
use App\Services\ProjectNavigation;
use App\Services\ViewColumnLayout;
use App\Services\ViewFieldCatalog;
use App\Services\ViewGridPayload;
use App\Services\WorkItemScreenPayload;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Project Workspace → Views (Views §5, §6, §13).
 *
 * Built like Pages: one Vue root serving both the listing and a single View, with `viewId`
 * telling the client which to render — so a View has a real, linkable URL rather than being a
 * mode of the list screen.
 *
 * Cross-project ids 404 rather than 403 throughout, so a response never confirms that a View
 * exists somewhere the caller cannot see. A private View belonging to someone else is the same
 * 404 for the same reason.
 */
class ProjectViewController extends Controller
{
    public function __construct(
        private readonly ProjectNavigation $navigation,
        private readonly ViewColumnLayout $layout,
        private readonly ViewFieldCatalog $catalog,
        private readonly ViewGridPayload $grid,
        private readonly WorkItemScreenPayload $workItems,
    ) {}

    /** GET /projects/{project}/views */
    public function index(Project $project): ViewContract
    {
        abort_unless(Auth::user()->can('viewAny', [ProjectView::class, $project]), 404);

        return $this->screen($project);
    }

    /** GET /projects/{project}/views/{view} — the grid (§7). */
    public function show(Project $project, ProjectView $view): ViewContract
    {
        $this->guard($project, $view, 'view');

        return $this->screen($project, $view);
    }

    /**
     * GET /projects/{project}/views/{view}/external — the grid, and nothing else.
     *
     * The same screen and the same data, with the application chrome removed: no workspace
     * sidebar, no project tab bar, just a slim header carrying Back and Close. It is what a
     * View looks like when the View *is* the page — useful on a projector or a second monitor,
     * and it is the shape §18.3 asks a published link to have, so Slice 3 inherits the layout
     * rather than inventing one.
     *
     * Authorization is unchanged. "External" here is about chrome, not about access — this
     * route is behind the same auth and the same policy as any other. Anonymous access is a
     * published link with a token, which is Slice 3.
     */
    public function external(Project $project, ProjectView $view): ViewContract
    {
        $this->guard($project, $view, 'view');

        return $this->screen($project, $view, external: true);
    }

    /** POST /projects/{project}/views (§6). */
    public function store(StoreProjectViewRequest $request, Project $project): JsonResponse
    {
        // The ability is checked by the form request, which runs first.
        $view = DB::transaction(function () use ($request, $project) {
            $view = ProjectView::create($request->validated() + [
                // §6/AC-06 elsewhere in this app: the project comes from the URL, never the payload.
                'project_id' => $project->id,
                'owner_user_id' => Auth::id(),
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]);

            // §6.4's starting columns, in one transaction with the View — a View with no
            // columns is not a thing the rest of the code should ever have to handle.
            $this->layout->seedDefaults($view, $project);

            return $view;
        });

        return response()->json([
            'ok' => true,
            'view' => $this->card($view->fresh(['owner', 'editor'])),
            'message' => 'View created.',
        ], 201);
    }

    /** PATCH /projects/{project}/views/{view} — rename, visibility, density (§5.3, §12.5). */
    public function update(UpdateProjectViewRequest $request, Project $project, ProjectView $view): JsonResponse
    {
        abort_unless((int) $view->project_id === (int) $project->id, 404);

        $view->fill($request->validated() + ['updated_by' => Auth::id()])->save();

        return response()->json([
            'ok' => true,
            'view' => $this->card($view->fresh(['owner', 'editor'])),
            'message' => 'View updated.',
        ]);
    }

    /**
     * POST /projects/{project}/views/{view}/duplicate (§5.3).
     *
     * The copy is owned by whoever duplicated it and is always PRIVATE, whatever the source
     * was. Sharing is an act; a duplicate that quietly appeared in everyone's list because the
     * original was shared would be a surprise, and the owner can share it in one click.
     */
    public function duplicate(Project $project, ProjectView $view): JsonResponse
    {
        $this->guard($project, $view, 'view');
        abort_unless(Auth::user()->can('create', [ProjectView::class, $project]), 403);

        $copy = DB::transaction(function () use ($view) {
            $copy = ProjectView::create([
                'project_id' => $view->project_id,
                'name' => mb_substr($view->name.' (copy)', 0, (int) config('projects.view_name_max')),
                'dataset_type' => $view->dataset_type,
                'visibility' => ProjectView::VISIBILITY_PRIVATE,
                'owner_user_id' => Auth::id(),
                'density' => $view->density,
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]);

            // Columns are copied wholesale, including ones whose feature is currently off:
            // §9.3 keeps those configurations, and a duplicate that silently dropped them
            // would destroy exactly what the spec asks to preserve.
            foreach ($view->columns as $column) {
                ProjectViewColumn::create($column->only([
                    'source_type', 'source_field', 'display_name', 'position_type',
                    'sort_order', 'width', 'is_visible', 'is_editable', 'formatting_json',
                ]) + ['project_view_id' => $copy->id]);
            }

            return $copy;
        });

        return response()->json([
            'ok' => true,
            'view' => $this->card($copy->fresh(['owner', 'editor'])),
            'message' => 'View duplicated.',
        ], 201);
    }

    /**
     * DELETE /projects/{project}/views/{view} (§5.3).
     *
     * Soft, like everything else in the project workspace: §4.2's principle is that View
     * configuration survives, and a delete the owner regrets should be recoverable.
     */
    public function destroy(Project $project, ProjectView $view): JsonResponse
    {
        abort_unless((int) $view->project_id === (int) $project->id, 404);
        abort_unless(Auth::user()->can('delete', $view), 403);

        $view->delete();

        return response()->json(['ok' => true, 'message' => 'View deleted.']);
    }

    /** POST /projects/{project}/views/{view}/favorite — toggles (§5.2). */
    public function favorite(Project $project, ProjectView $view): JsonResponse
    {
        $this->guard($project, $view, 'view');

        $existing = ProjectViewFavorite::query()
            ->where('project_view_id', $view->id)
            ->where('user_id', Auth::id())
            ->first();

        if ($existing) {
            $existing->delete();
        } else {
            ProjectViewFavorite::create(['project_view_id' => $view->id, 'user_id' => Auth::id()]);
        }

        return response()->json(['ok' => true, 'favorite' => $existing === null]);
    }

    /**
     * The Views screen — the listing, or one View's grid.
     *
     * Both are the same Vue root because they share the create dialog, the toast plumbing and
     * the project chrome; `viewId` is what decides which one renders.
     */
    private function screen(Project $project, ?ProjectView $open = null, bool $external = false): ViewContract
    {
        $user = Auth::user();

        $views = ProjectView::query()
            ->forProject($project->id)
            ->visibleTo($user)
            ->with(['owner', 'editor'])
            ->orderByDesc('updated_at')
            ->get();

        $favorites = ProjectViewFavorite::query()
            ->whereIn('project_view_id', $views->pluck('id'))
            ->where('user_id', $user->id)
            ->pluck('project_view_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return view($external ? 'projects.views-external' : 'projects.views', [
            'workspace' => $user->currentWorkspace,
            'user' => $user,
            'project' => $project,
            'tabs' => $this->navigation->tabs($project),
            'activeTab' => 'views',
            'projects' => $this->navigation->sidebarProjects($user),
            'canCreateProject' => $user->can('create', [WorkItem::class, $project]),
            'canManage' => $user->can('manage', $project),
            'bootstrap' => [
                'project' => [
                    'id' => $project->id,
                    'name' => $project->name,
                    'identifier' => $project->identifier,
                ],
                'views' => $views->map(fn (ProjectView $v) => $this->card($v, in_array((int) $v->id, $favorites, true)))->all(),
                // Set only on a View's own URL: render the grid, not the listing.
                'viewId' => $open?->id,
                // The open View's configuration. Columns travel with the page so the grid can
                // build itself before the first row request comes back.
                'columns' => $open ? $this->layout->describe($open, $project) : [],
                'frozen' => $open ? $this->layout->frozenCount($open) : 0,
                'fields' => $open ? $this->catalog->selector($project, collect($open->columns)->map->key()->all()) : [],
                'sortable' => $this->grid->sortableKeys(),
                // The grid pages in the same steps the endpoint does — a pager that offered a
                // page size the server does not honour would skip rows.
                'pageSize' => (int) config('projects.view_page_size'),
                'densities' => collect(config('projects.view_densities'))
                    ->map(fn (array $meta, string $key) => ['key' => $key] + $meta)->values()->all(),
                'visibilities' => $this->visibilityOptions($project),
                'nameMax' => (int) config('projects.view_name_max'),
                'canCreate' => $user->can('create', [ProjectView::class, $project]),
                // Chrome only: the screen hides the listing controls and renders the slim
                // header. Every permission below is resolved exactly as it is anywhere else.
                'external' => $external,
                'externalUrl' => $open ? route('projects.views.external', ['project' => $project->id, 'view' => $open->id]) : null,
                // Configuration editing and data editing are separate permissions (§14), so
                // they are separate answers — the client must not infer one from the other.
                'canEditView' => $open ? $user->can('update', $open) : false,
                'canEditData' => $open ? $user->can('updateData', $open) : false,
                // §11.1's inline editing uses the SAME pickers the work items screen does,
                // so it needs the same option lists — taken from there rather than rebuilt,
                // so the two screens can never offer different choices.
                'options' => $open ? $this->workItems->pickerOptions($project) : [],
                'endpoints' => $this->endpoints($project),
            ],
        ]);
    }

    /** @return array<int, array<string, string>> */
    private function visibilityOptions(Project $project): array
    {
        $options = [];

        if ($project->featureEnabled('view_private')) {
            $options[] = ['key' => ProjectView::VISIBILITY_PRIVATE, 'label' => 'Private', 'hint' => 'Only you can open it.'];
        }

        if ($project->featureEnabled('view_project')) {
            $options[] = ['key' => ProjectView::VISIBILITY_PROJECT, 'label' => 'Project', 'hint' => 'Anyone on this project can open it.'];
        }

        return $options;
    }

    /** @return array<string, string> */
    private function endpoints(Project $project): array
    {
        $id = ['project' => $project->id, 'view' => '__ID__'];

        return [
            'list' => route('projects.views', $project),
            'store' => route('projects.views.store', $project),
            'open' => route('projects.views.show', $id),
            'update' => route('projects.views.update', $id),
            'duplicate' => route('projects.views.duplicate', $id),
            'destroy' => route('projects.views.destroy', $id),
            'favorite' => route('projects.views.favorite', $id),
            'rows' => route('projects.views.rows', $id),
            'fields' => route('projects.views.fields', $id),
            'columns' => route('projects.views.columns.store', $id),
            'column' => route('projects.views.columns.update', $id + ['column' => '__COLUMN__']),
            'columnOrder' => route('projects.views.columns.order', $id),
            'cell' => route('projects.views.rows.update', $id + ['workItem' => '__ITEM__']),
        ];
    }

    /** The View must belong to THIS project, and the caller must hold the ability. */
    private function guard(Project $project, ProjectView $view, string $ability): void
    {
        abort_unless((int) $view->project_id === (int) $project->id, 404);
        // 404 for a read, so an unreadable View is indistinguishable from one that is not there.
        abort_unless(Auth::user()->can($ability, $view), $ability === 'view' ? 404 : 403);
    }

    /** @return array<string, mixed> */
    private function card(ProjectView $view, ?bool $favorite = null): array
    {
        return [
            'id' => $view->id,
            'name' => $view->name,
            'dataset_type' => $view->dataset_type,
            'dataset_label' => 'Work items',
            'visibility' => $view->visibility,
            'visibility_label' => $view->isPrivate() ? 'Private' : 'Project',
            'density' => $view->density,
            'owner' => $view->owner ? $this->person($view->owner) : null,
            'is_owner' => $view->isOwnedBy(Auth::user()),
            'favorite' => $favorite ?? false,
            // §5.2 lists a published status. Publishing lands in Slice 3; saying "not
            // published" is honest, and the listing keeps its column rather than gaining one.
            'published' => false,
            'updated_at' => $view->updated_at?->toIso8601String(),
            'url' => route('projects.views.show', ['project' => $view->project_id, 'view' => $view->id]),
        ];
    }

    /** @return array<string, mixed> */
    private function person(User $user): array
    {
        return [
            'id' => $user->id, 'name' => $user->displayName(),
            'initial' => $user->initial(), 'avatar_url' => $user->avatar_url,
        ];
    }
}
