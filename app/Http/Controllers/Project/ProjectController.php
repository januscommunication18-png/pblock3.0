<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Http\Requests\Project\StoreProjectRequest;
use App\Http\Requests\Project\UpdateProjectDetailsRequest;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\ProjectPriority;
use App\Models\ProjectState;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\ProjectAccess;
use App\Services\ProjectCreator;
use App\Services\ProjectLifecycle;
use App\Services\ProjectNavigation;
use App\Services\WorkspaceSettingsManager;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * Workspace → Projects: list, create, open, and lifecycle (archive/restore/delete).
 * Tenancy is initialized to the current workspace by the workspace.tenancy middleware, so
 * Project route-model bindings and queries are auto-confined to it (PRJ-002/032).
 */
class ProjectController extends Controller
{
    public function __construct(
        private readonly ProjectCreator $creator,
        private readonly ProjectLifecycle $lifecycle,
        private readonly ProjectNavigation $navigation,
    ) {}

    /** @var Collection<int, ProjectState>|null Memoized workspace states. */
    private $stateMap = null;

    /** Identity (user + workspace) the memos below were built for. */
    private ?string $memoKey = null;

    private function workspace(): Workspace
    {
        return Auth::user()->currentWorkspace;
    }

    /**
     * Drop the per-request memos when the acting user or workspace changes.
     *
     * The router caches ONE controller instance on the Route object, so this instance is
     * reused for every request in a long-running process (Octane, and any feature test that
     * makes more than one request). Without this guard, request 2 would answer with
     * request 1's workspace role, states and priorities.
     */
    private function syncMemos(): void
    {
        $key = Auth::id().'|'.(Auth::user()?->current_workspace_id ?? '');
        if ($this->memoKey === $key) {
            return;
        }
        $this->memoKey = $key;
        $this->stateMap = null;
        $this->priorityMap = null;
        $this->wsRole = null;
        $this->wsRoleLoaded = false;
    }

    /** Workspace project states keyed by id (memoized for the request). */
    private function statesMap()
    {
        $this->syncMemos();

        return $this->stateMap ??= ProjectState::query()->orderBy('position')->get()->keyBy('id');
    }

    /** The workspace's default project state (is_default, else lowest position). */
    private function defaultState()
    {
        $states = $this->statesMap();

        return $states->firstWhere('is_default', true) ?? $states->first();
    }

    /** @return array<int, array<string, mixed>> Status options for the card chip. */
    private function stateOptions(): array
    {
        return $this->statesMap()->values()
            ->map(fn (ProjectState $s) => ['id' => $s->id, 'name' => $s->name, 'color' => $s->color])
            ->all();
    }

    /** @var Collection<int, ProjectPriority>|null */
    private $priorityMap = null;

    /** Workspace project priorities keyed by id (memoized). Empty if not migrated yet. */
    private function prioritiesMap()
    {
        $this->syncMemos();

        if ($this->priorityMap !== null) {
            return $this->priorityMap;
        }
        if (! Schema::hasTable('project_priorities')) {
            return $this->priorityMap = collect();
        }

        return $this->priorityMap = ProjectPriority::query()->orderBy('position')->get()->keyBy('id');
    }

    /** @return array<int, array<string, mixed>> Priority options for the card chip. */
    private function priorityOptions(): array
    {
        return $this->prioritiesMap()->values()
            ->map(fn (ProjectPriority $p) => ['id' => $p->id, 'name' => $p->name, 'color' => $p->color])
            ->all();
    }

    /** GET /projects */
    public function index(Request $request): View
    {
        $user = Auth::user();
        $workspace = $this->workspace();
        $archived = (bool) $request->boolean('archived');

        return view('projects.index', [
            'workspace' => $workspace,
            'user' => $user,
            'projects' => $this->navProjects(),
            'canCreateProject' => $user->can('create', [Project::class, $workspace]),
            'bootstrap' => [
                'projects' => $this->visibleProjects($archived ? Project::STATUS_ARCHIVED : Project::STATUS_ACTIVE),
                'archived' => $archived,
                'canCreate' => $user->can('create', [Project::class, $workspace]),
                'members' => $this->workspaceMembers(),
                'visibilities' => $this->visibilityKeys(),
                'coverPresets' => config('projects.cover_presets') ?? config('projects.cover_gradients') ?? [],
                'states' => $this->stateOptions(),
                'priorities' => $this->priorityOptions(),
                'endpoints' => [
                    'store' => route('projects.store'),
                    'identifier' => route('projects.identifier'),
                    'list' => route('projects.index'),
                    // Edit modal — saves every field in one PATCH (PRJ-045).
                    'update' => Route::has('projects.update') ? route('projects.update', ['project' => '__ID__']) : null,
                    'state' => Route::has('projects.state') ? route('projects.state', ['project' => '__ID__']) : null,
                    'lead' => Route::has('projects.lead') ? route('projects.lead', ['project' => '__ID__']) : null,
                    'priority' => Route::has('projects.priority') ? route('projects.priority', ['project' => '__ID__']) : null,
                    'dates' => Route::has('projects.dates') ? route('projects.dates', ['project' => '__ID__']) : null,
                    // Card action menu — Edit / Archive (or Restore) / Delete (PRJ-045/046/047).
                    'archive' => Route::has('projects.archive') ? route('projects.archive', ['project' => '__ID__']) : null,
                    'restore' => Route::has('projects.restore') ? route('projects.restore', ['project' => '__ID__']) : null,
                    'destroy' => Route::has('projects.destroy') ? route('projects.destroy', ['project' => '__ID__']) : null,
                ],
            ],
        ]);
    }

    /** POST /projects */
    public function store(StoreProjectRequest $request): JsonResponse
    {
        $workspace = $this->workspace();
        abort_unless(Auth::user()->can('create', [Project::class, $workspace]), 403);

        $project = $this->creator->create(Auth::user(), $workspace, $request->validated());

        // Project states are seeded lazily; make sure this workspace's defaults exist before
        // reading them, so a project created right after signup still gets a status.
        app(WorkspaceSettingsManager::class)->for($workspace);
        $this->stateMap = null; // refresh the memoized map now that states are provisioned

        // Default the project's status to the workspace's default state (SET-PROJ / PRJ-043).
        // Guarded by hasColumn so creation never breaks if the migration hasn't run yet.
        if (empty($project->state_id) && Schema::hasColumn('projects', 'state_id')) {
            $default = $this->defaultState();
            if ($default) {
                $project->state_id = $default->id;
                $project->save();
            }
        }

        return response()->json([
            'ok' => true,
            'project' => $this->card($project->fresh()),
            'redirect' => route('projects.show', $project),
        ]);
    }

    /**
     * PATCH /projects/{project} — save the card's Edit modal (PRJ-045).
     *
     * One request for everything the modal shows: the create-modal fields plus the four
     * chips the card edits individually (status, priority, start/end date), so an edit is a
     * single atomic save instead of five separate PATCHes.
     */
    public function update(UpdateProjectDetailsRequest $request, Project $project): JsonResponse
    {
        // Editing a project's details is workspace Owner/Admin only — narrower than the
        // manage rights that gate archive/restore/delete, which project admins also hold.
        abort_unless($this->isWorkspaceAdmin(), 403);

        $data = $request->validated();

        // `identifier` is intentionally not writable here — the identifier is the permanent
        // @mention handle, so the modal shows it read-only and any posted value is ignored.
        $project->name = $data['name'];
        $project->description = $data['description'] ?? null;
        $project->visibility = $data['visibility'];
        $project->lead_user_id = $data['lead_user_id'] ?? null;

        // PRJ-027: a project's cover is EITHER an uploaded image or a gradient, never both —
        // that is how the modal presents it, where picking a swatch visibly deselects the
        // upload. Choosing a gradient therefore has to clear the image, or `cover_url` keeps
        // winning in the card and the swatch appears to do nothing.
        if (Schema::hasColumn('projects', 'cover_gradient') && array_key_exists('cover_gradient', $data)) {
            $gradient = $data['cover_gradient'] ?: null;
            $project->cover_gradient = $gradient;

            if ($gradient !== null) {
                $project->cover_url = null;
            }
        }

        // Status/priority must belong to THIS workspace's configured sets (same rule the
        // per-chip endpoints enforce). Dates and priority are guarded by hasColumn so an
        // edit still saves on an install where the later migrations have not run.
        if (($stateId = $data['state_id'] ?? null) !== null) {
            abort_unless($this->statesMap()->has((int) $stateId), 422, 'That status is not available in this workspace.');
        }
        $project->state_id = $stateId !== null ? (int) $stateId : null;

        if (Schema::hasColumn('projects', 'priority_id')) {
            if (($priorityId = $data['priority_id'] ?? null) !== null) {
                abort_unless($this->prioritiesMap()->has((int) $priorityId), 422, 'That priority is not available in this workspace.');
            }
            $project->priority_id = $priorityId !== null ? (int) $priorityId : null;
        }

        if (Schema::hasColumn('projects', 'start_date')) {
            $project->start_date = $data['start_date'] ?? null;
            $project->end_date = $data['end_date'] ?? null;
        }

        $project->save();

        return response()->json([
            'ok' => true,
            'project' => $this->card($project->fresh()),
        ]);
    }

    /** PATCH /projects/{project}/state — set the project's workspace status (chip on the card). */
    public function setState(Request $request, Project $project): JsonResponse
    {
        abort_unless($this->canManageProject($project), 403);

        $stateId = $request->input('state_id');
        if ($stateId === null || $stateId === '') {
            $project->state_id = null;
        } else {
            $state = ProjectState::query()->find($stateId);
            abort_unless($state !== null, 422, 'That status is not available in this workspace.');
            $project->state_id = $state->id;
        }
        $project->save();

        // Effective status: explicit if set, else the workspace default (mirrors the card).
        $s = ($project->state_id ? $this->statesMap()->get($project->state_id) : null) ?: $this->defaultState();

        return response()->json([
            'ok' => true,
            'state' => $s ? ['id' => $s->id, 'name' => $s->name, 'color' => $s->color] : null,
        ]);
    }

    /** PATCH /projects/{project}/lead — set the project's lead (editable combo on the card). */
    public function setLead(Request $request, Project $project): JsonResponse
    {
        abort_unless($this->canManageProject($project), 403);

        $leadId = $request->input('lead_user_id');
        if ($leadId === null || $leadId === '') {
            $project->lead_user_id = null;
        } else {
            $isMember = WorkspaceMembership::query()
                ->where('workspace_id', $this->workspace()->id)
                ->where('user_id', $leadId)
                ->where('status', WorkspaceMembership::STATUS_ACTIVE)
                ->exists();
            abort_unless($isMember, 422, 'That person is not a member of this workspace.');
            $project->lead_user_id = (int) $leadId;
        }
        $project->save();
        $project->load('lead');
        $lead = $project->lead;

        return response()->json([
            'ok' => true,
            'lead' => $lead ? ['id' => $lead->id, 'name' => $lead->displayName(), 'initial' => $lead->initial()] : null,
        ]);
    }

    /** PATCH /projects/{project}/priority — set the project's priority (editable chip). */
    public function setPriority(Request $request, Project $project): JsonResponse
    {
        abort_unless($this->canManageProject($project), 403);
        abort_unless(Schema::hasColumn('projects', 'priority_id'), 422, 'Run migrations to enable priorities.');

        $priorityId = $request->input('priority_id');
        if ($priorityId === null || $priorityId === '') {
            $project->priority_id = null;
        } else {
            abort_unless($this->prioritiesMap()->has((int) $priorityId), 422, 'That priority is not available in this workspace.');
            $project->priority_id = (int) $priorityId;
        }
        $project->save();

        $p = $project->priority_id ? $this->prioritiesMap()->get($project->priority_id) : null;

        return response()->json([
            'ok' => true,
            'priority' => $p ? ['id' => $p->id, 'name' => $p->name, 'color' => $p->color] : null,
        ]);
    }

    /** PATCH /projects/{project}/dates — set the project's start/end dates (editable chips). */
    public function setDates(Request $request, Project $project): JsonResponse
    {
        abort_unless($this->canManageProject($project), 403);
        abort_unless(Schema::hasColumn('projects', 'start_date'), 422, 'Run migrations to enable dates.');

        $data = $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);

        if ($request->exists('start_date')) {
            $project->start_date = $data['start_date'] ?: null;
        }
        if ($request->exists('end_date')) {
            $project->end_date = $data['end_date'] ?: null;
        }
        $project->save();

        return response()->json([
            'ok' => true,
            'start_date' => $this->dateStr($project->start_date),
            'end_date' => $this->dateStr($project->end_date),
        ]);
    }

    /** Normalize a date attribute (Carbon or string) to a Y-m-d string, or null. */
    private function dateStr($d): ?string
    {
        if ($d instanceof \DateTimeInterface) {
            return $d->format('Y-m-d');
        }

        return $d ? substr((string) $d, 0, 10) : null;
    }

    /** GET /projects/identifier-available?identifier=ABC (PRJ-022 live check). */
    public function identifierAvailable(Request $request): JsonResponse
    {
        $id = strtolower(preg_replace('/[^A-Za-z0-9]/', '', (string) $request->query('identifier')));
        $valid = $id !== ''
            && preg_match(config('projects.identifier.regex'), $id) === 1
            && ! in_array($id, config('projects.reserved_identifiers') ?? [], true);
        $available = $valid && ! Project::query()->where('identifier', $id)->exists();

        return response()->json(['identifier' => $id, 'available' => $available]);
    }

    /**
     * GET /projects/{project} — opening a project enters its Project Workspace.
     *
     * Phase 5 requirement §11.1: selecting a project opens that project's workspace with
     * Work Items selected, since Overview is Coming Soon. Kept as a redirect rather than a
     * moved route so every existing `projects.show` link (cards, sidebar, post-create
     * redirect) keeps working.
     */
    public function show(Project $project): RedirectResponse
    {
        // 403 when the project is public and the user is in its workspace, 404 otherwise —
        // ProjectAccess owns that choice (docs/features/workspace-project-access.md §7).
        app(ProjectAccess::class)->guardView(Auth::user(), $project);

        return redirect()->route('projects.work-items', $project);
    }

    /** POST /projects/{project}/archive */
    public function archive(Project $project): JsonResponse
    {
        abort_unless($this->canManageProject($project), 403);
        $this->lifecycle->archive($project);

        return response()->json(['ok' => true]);
    }

    /** POST /projects/{project}/restore */
    public function restore(Project $project): JsonResponse
    {
        abort_unless($this->canManageProject($project), 403);
        $this->lifecycle->restore($project);

        return response()->json(['ok' => true]);
    }

    /** DELETE /projects/{project} — permanent delete with typed confirmation (PRJ-047). */
    public function destroy(Request $request, Project $project): JsonResponse
    {
        abort_unless(Auth::user()->can('delete', $project), 403);

        $confirm = strtoupper(trim((string) $request->input('confirm')));
        // Compared case-insensitively: the confirmation is about intent, not typing precision.
        abort_if(strtolower($confirm) !== strtolower((string) $project->identifier), 422, 'Type the identifier to confirm deletion.');

        $this->lifecycle->delete($project);

        return response()->json(['ok' => true, 'redirect' => route('projects.index')]);
    }

    /**
     * Projects of a given status visible to the current user (PRJ-010/030/031).
     *
     * The set comes from `ProjectNavigation::visible()` — the same query behind the left-hand
     * sidebar, the /welcome shell and Your Work — so this page and the navigation beside it
     * cannot list different things (docs/features/workspace-project-access.md §2/§6). It used
     * to restate the rule here, which is how two lists drawn from "the same" definition drifted.
     *
     * @return array<int, array<string, mixed>>
     */
    private function visibleProjects(string $status): array
    {
        $user = Auth::user();

        $projects = $this->navigation->visible($user, $status)
            ->with('lead')
            ->limit((int) (config('projects.page_size') ?? 24))
            ->get();

        $memberIds = ProjectMember::query()->where('user_id', $user->id)->pluck('project_id')->flip();

        return $projects->map(fn (Project $p) => $this->card($p, $memberIds->has($p->id)))->all();
    }

    /**
     * Compact list of active projects visible to the user for the left sidebar.
     *
     * @return array<int, array<string, mixed>>
     */
    private function navProjects(): array
    {
        return $this->navigation->sidebarProjects(Auth::user());
    }

    /** @return array<string, mixed> */
    private function card(Project $project, ?bool $joined = null): array
    {
        $lead = $project->lead;

        return [
            'id' => $project->id,
            'name' => $project->name,
            'identifier' => $project->identifier,
            'description' => $project->description,
            'visibility' => $project->visibility,
            'emoji' => $project->emoji,
            'cover_url' => $project->cover_url,
            'cover_gradient' => $project->cover_gradient,
            'status' => $project->status,
            'lead' => $lead ? ['id' => $lead->id, 'name' => $lead->displayName(), 'initial' => $lead->initial()] : null,
            // Explicit status if set, otherwise fall back to the workspace default state
            // (so a project without a chosen status still reads as "Draft", etc.).
            'state' => ($st = ($project->state_id ? $this->statesMap()->get($project->state_id) : null) ?: $this->defaultState())
                ? ['id' => $st->id, 'name' => $st->name, 'color' => $st->color]
                : null,
            'priority' => ($pr = $project->priority_id ? $this->prioritiesMap()->get($project->priority_id) : null)
                ? ['id' => $pr->id, 'name' => $pr->name, 'color' => $pr->color]
                : null,
            'start_date' => $this->dateStr($project->start_date),
            'end_date' => $this->dateStr($project->end_date),
            'can_manage' => $this->canManageProject($project),
            // Edit is workspace Owner/Admin only; can_manage still covers archive/delete.
            'can_edit' => $this->isWorkspaceAdmin(),
            'joined' => $joined ?? ProjectMember::query()->where('project_id', $project->id)->where('user_id', Auth::id())->exists(),
            'url' => Route::has('projects.show') ? route('projects.show', $project) : '#',
            'settings_url' => Route::has('projects.settings')
                ? route('projects.settings', ['project' => $project->id, 'section' => 'general'])
                : null,
        ];
    }

    /** @return array<int, array<string, mixed>> Workspace members for the lead selector. */
    private function workspaceMembers(): array
    {
        return WorkspaceMembership::query()
            ->where('workspace_id', $this->workspace()->id)
            ->where('status', WorkspaceMembership::STATUS_ACTIVE)
            ->with('user')
            ->get()
            ->map(fn (WorkspaceMembership $m) => [
                'id' => $m->user_id,
                'name' => $m->user?->displayName(),
                'email' => $m->user?->email,
                'initial' => $m->user?->initial(),
            ])->all();
    }

    /**
     * Visibility option keys, tolerant of either a flat list ['public','private'] or an
     * associative ['public' => 'Public'] config shape.
     *
     * @return array<int, string>
     */
    private function visibilityKeys(): array
    {
        $v = config('projects.visibilities') ?? ['public', 'private'];

        return array_values(array_is_list($v) ? $v : array_keys($v));
    }

    private ?string $wsRole = null;

    private bool $wsRoleLoaded = false;

    private function workspaceRole(): ?string
    {
        $this->syncMemos();

        if (! $this->wsRoleLoaded) {
            $this->wsRole = WorkspaceMembership::query()
                ->where('workspace_id', $this->workspace()->id)
                ->where('user_id', Auth::id())
                ->where('status', WorkspaceMembership::STATUS_ACTIVE)
                ->value('role');
            $this->wsRoleLoaded = true;
        }

        return $this->wsRole;
    }

    /**
     * Can the current user manage this project? Workspace owners/admins can manage any
     * project; otherwise the user must be a project admin. Computed directly (not via a
     * policy ability) so it doesn't depend on a specific policy method name.
     */
    /** Is the current user a workspace Owner or Admin? Gates the card's Edit action. */
    private function isWorkspaceAdmin(): bool
    {
        return in_array($this->workspaceRole(), ['owner', 'admin'], true);
    }

    private function canManageProject(Project $project): bool
    {
        if ($this->isWorkspaceAdmin()) {
            return true;
        }

        return ProjectMember::query()
            ->where('project_id', $project->id)
            ->where('user_id', Auth::id())
            ->where('role', 'admin')
            ->exists();
    }
}
