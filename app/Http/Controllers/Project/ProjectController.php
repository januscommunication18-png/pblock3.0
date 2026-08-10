<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Http\Requests\Project\StoreProjectRequest;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\ProjectPriority;
use App\Models\ProjectState;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\ProjectCreator;
use App\Services\ProjectLifecycle;
use App\Services\WorkspaceSettingsManager;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
    ) {}

    /** @var \Illuminate\Support\Collection<int, ProjectState>|null Memoized workspace states. */
    private $stateMap = null;

    private function workspace(): Workspace
    {
        return Auth::user()->currentWorkspace;
    }

    /** Workspace project states keyed by id (memoized for the request). */
    private function statesMap()
    {
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

    /** @var \Illuminate\Support\Collection<int, ProjectPriority>|null */
    private $priorityMap = null;

    /** Workspace project priorities keyed by id (memoized). Empty if not migrated yet. */
    private function prioritiesMap()
    {
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
                    'state' => Route::has('projects.state') ? route('projects.state', ['project' => '__ID__']) : null,
                    'lead' => Route::has('projects.lead') ? route('projects.lead', ['project' => '__ID__']) : null,
                    'priority' => Route::has('projects.priority') ? route('projects.priority', ['project' => '__ID__']) : null,
                    'dates' => Route::has('projects.dates') ? route('projects.dates', ['project' => '__ID__']) : null,
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
        $id = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $request->query('identifier')));
        $valid = $id !== ''
            && preg_match(config('projects.identifier.regex'), $id) === 1
            && ! in_array($id, config('projects.reserved_identifiers') ?? [], true);
        $available = $valid && ! Project::query()->where('identifier', $id)->exists();

        return response()->json(['identifier' => $id, 'available' => $available]);
    }

    /** GET /projects/{project} — the project landing view (PRJ-028 post-create destination). */
    public function show(Project $project): View
    {
        abort_unless(Auth::user()->can('view', $project), 404); // 404, never leak metadata

        return view('projects.show', [
            'workspace' => $this->workspace(),
            'user' => Auth::user(),
            'project' => $this->card($project),
            'canManage' => $this->canManageProject($project),
            'projects' => $this->navProjects(),
            'canCreateProject' => Auth::user()->can('create', [Project::class, $this->workspace()]),
        ]);
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
        abort_if($confirm !== strtoupper($project->identifier), 422, 'Type the project ID to confirm deletion.');

        $this->lifecycle->delete($project);

        return response()->json(['ok' => true, 'redirect' => route('projects.index')]);
    }

    /**
     * Projects of a given status visible to the current user (PRJ-010/030/031). Owner/Admin
     * see all; others see public projects plus projects they are a member of.
     *
     * @return array<int, array<string, mixed>>
     */
    private function visibleProjects(string $status): array
    {
        $user = Auth::user();
        $wsRole = $this->workspaceRole();

        $query = Project::query()->where('status', $status)->with('lead')->latest();

        if (! in_array($wsRole, ['owner', 'admin'], true)) {
            $memberProjectIds = ProjectMember::query()->where('user_id', $user->id)->pluck('project_id');
            $query->where(function ($q) use ($memberProjectIds, $wsRole) {
                $q->whereIn('id', $memberProjectIds);
                if (in_array($wsRole, ['member', 'viewer'], true)) {
                    $q->orWhere('visibility', 'public');
                }
            });
        }

        $memberIds = ProjectMember::query()->where('user_id', $user->id)->pluck('project_id')->flip();

        return $query->limit((int) (config('projects.page_size') ?? 24))->get()
            ->map(fn (Project $p) => $this->card($p, $memberIds->has($p->id)))
            ->all();
    }

    /**
     * Compact list of active projects visible to the user for the left sidebar.
     *
     * @return array<int, array<string, mixed>>
     */
    private function navProjects(): array
    {
        $user = Auth::user();
        $wsRole = $this->workspaceRole();

        $query = Project::query()->where('status', Project::STATUS_ACTIVE)->latest();
        if (! in_array($wsRole, ['owner', 'admin'], true)) {
            $ids = ProjectMember::query()->where('user_id', $user->id)->pluck('project_id');
            $query->where(function ($q) use ($ids, $wsRole) {
                $q->whereIn('id', $ids);
                if (in_array($wsRole, ['member', 'viewer'], true)) {
                    $q->orWhere('visibility', 'public');
                }
            });
        }

        return $query->limit(50)->get()
            ->map(fn (Project $p) => ['name' => $p->name, 'emoji' => $p->emoji, 'url' => route('projects.show', $p->id)])
            ->all();
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
    private function canManageProject(Project $project): bool
    {
        if (in_array($this->workspaceRole(), ['owner', 'admin'], true)) {
            return true;
        }

        return ProjectMember::query()
            ->where('project_id', $project->id)
            ->where('user_id', Auth::id())
            ->where('role', 'admin')
            ->exists();
    }
}
