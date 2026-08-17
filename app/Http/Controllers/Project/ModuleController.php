<?php

namespace App\Http\Controllers\Project;

use App\Filters\FilterRegistry;
use App\Filters\FilterSet;
use App\Http\Controllers\Controller;
use App\Http\Requests\Project\StoreModuleRequest;
use App\Http\Requests\Project\UpdateModuleRequest;
use App\Models\Module;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkspaceMembership;
use App\Services\ModuleProgress;
use App\Services\ProjectFeatureState;
use App\Services\ProjectNavigation;
use App\Services\WorkItemBlockers;
use App\Services\WorkItemScreenPayload;
use App\Services\WorkItemStatusUpdates;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Project Workspace → Modules (Module Management §7-§13).
 *
 * Built like Cycles: one Vue root and one payload for both the list and a single module, with
 * `pageModuleId` telling the client to render the detail instead of the landing page — so a
 * module URL is real and linkable rather than a list with a panel over it.
 *
 * Every action starts from ModulePolicy, which refuses outright when Modules is switched off
 * for the project (§4.2). Cross-project ids 404 rather than 403, so a response never confirms
 * that a module exists somewhere the user cannot see (§20 Security).
 */
class ModuleController extends Controller
{
    public function __construct(
        private readonly ProjectFeatureState $featureState,
        private readonly ModuleProgress $progress,
        private readonly ProjectNavigation $navigation,
        private readonly WorkItemBlockers $blockers,
        private readonly WorkItemStatusUpdates $statusUpdates,
        private readonly WorkItemScreenPayload $payload,
    ) {}

    /** GET /projects/{project}/modules */
    public function index(Project $project): View
    {
        abort_unless(Auth::user()->can('viewAny', [Module::class, $project]), 404);

        return $this->screen($project);
    }

    /** GET /projects/{project}/modules/{module} — the stable per-module URL (§8). */
    public function show(Project $project, Module $module): View
    {
        $this->guardModule($project, $module, 'view');

        return $this->screen($project, $module);
    }

    /** POST /projects/{project}/modules (§5). */
    public function store(StoreModuleRequest $request, Project $project): JsonResponse
    {
        // The ability is checked by StoreModuleRequest::authorize(), which runs first.
        $data = $request->validated();

        $module = DB::transaction(function () use ($data, $project) {
            $module = Module::create(collect($data)->except('member_ids')->all() + [
                'project_id' => $project->id,
                'created_by' => Auth::id(),
            ]);
            $module->members()->sync($data['member_ids'] ?? []);

            return $module;
        });

        return response()->json([
            'ok' => true,
            'module' => $this->card($module->fresh(['lead', 'members', 'creator'])),
            'message' => 'Module created.',
        ], 201);
    }

    /** PATCH /projects/{project}/modules/{module} (§11). */
    public function update(UpdateModuleRequest $request, Project $project, Module $module): JsonResponse
    {
        // UpdateModuleRequest::authorize() has checked the ability; this is the ownership
        // half — a module reached through the wrong project's URL is not found.
        abort_unless($module->project_id === $project->id, 404);

        $data = $request->validated();

        DB::transaction(function () use ($module, $data) {
            $module->fill(collect($data)->except('member_ids')->all())->save();

            // §5.2: the form sends the whole list, so a removed member has to disappear.
            if (array_key_exists('member_ids', $data)) {
                $module->members()->sync($data['member_ids']);
            }
        });

        return response()->json([
            'ok' => true,
            'module' => $this->card($module->fresh(['lead', 'members', 'creator'])),
            'message' => 'Module updated.',
        ]);
    }

    /** POST /projects/{project}/modules/{module}/archive (§12). */
    public function archive(Project $project, Module $module): JsonResponse
    {
        $this->guardModule($project, $module, 'archive');

        // §12.2: the record and its work item links stay exactly as they are — archiving only
        // takes the module out of the active list and the work item picker.
        $module->forceFill(['archived_at' => now()])->save();

        return response()->json([
            'ok' => true,
            'module' => $this->card($module->fresh(['lead', 'members', 'creator'])),
            'message' => 'Module archived.',
        ]);
    }

    /** POST /projects/{project}/modules/{module}/restore (§12.2). */
    public function restore(Project $project, Module $module): JsonResponse
    {
        $this->guardModule($project, $module, 'archive');
        $module->forceFill(['archived_at' => null])->save();

        return response()->json([
            'ok' => true,
            'module' => $this->card($module->fresh(['lead', 'members', 'creator'])),
            'message' => 'Module restored.',
        ]);
    }

    /**
     * DELETE /projects/{project}/modules/{module} (§13).
     *
     * The work items survive and simply lose the association — §13.1 is explicit that they
     * must not be deleted. The pivot rows go with the module through the FK's cascade, so the
     * de-linking cannot be forgotten.
     */
    public function destroy(Project $project, Module $module): JsonResponse
    {
        $this->guardModule($project, $module, 'delete');
        $module->delete();

        return response()->json(['ok' => true, 'message' => 'Module deleted.']);
    }

    /**
     * POST /projects/{project}/modules/{module}/work-items — add existing items (§9.4).
     *
     * `syncWithoutDetaching` rather than attach: §15 forbids duplicate module-work item
     * relationships, and adding one that is already there should be a no-op rather than an
     * error the user has to understand.
     */
    public function addWorkItems(Request $request, Project $project, Module $module): JsonResponse
    {
        $this->guardModule($project, $module, 'manageWorkItems');

        $ids = $request->validate([
            'work_item_ids' => ['required', 'array', 'max:200'],
            'work_item_ids.*' => ['integer'],
        ])['work_item_ids'];

        // §15: only work items from THIS project, and only ones the user may actually touch.
        $items = WorkItem::query()
            ->forProject($project->id)
            ->whereIn('id', $ids)
            ->get()
            ->filter(fn (WorkItem $i) => Auth::user()->can('update', $i));

        $module->workItems()->syncWithoutDetaching(
            $items->mapWithKeys(fn (WorkItem $i) => [$i->id => ['created_by' => Auth::id()]])->all(),
        );

        return response()->json([
            'ok' => true,
            'module' => $this->card($module->fresh(['lead', 'members', 'creator'])),
            'items' => $this->workItems($module),
            'message' => $items->count() === 1 ? '1 work item added.' : "{$items->count()} work items added.",
        ]);
    }

    /** DELETE /projects/{project}/modules/{module}/work-items/{workItem} (§8.3). */
    public function removeWorkItem(Project $project, Module $module, WorkItem $workItem): JsonResponse
    {
        $this->guardModule($project, $module, 'manageWorkItems');

        // §8.3: only the association goes. The work item is untouched.
        $module->workItems()->detach($workItem->id);

        return response()->json([
            'ok' => true,
            'items' => $this->workItems($module),
            'message' => 'Work item removed from the module.',
        ]);
    }

    /**
     * GET /projects/{project}/modules/{module}/search?q= — candidates for "Add work items".
     *
     * Restricted to items the user may actually see, so the picker cannot become a way to
     * read titles that the project's Work Item View hides.
     */
    public function search(Request $request, Project $project, Module $module): JsonResponse
    {
        $this->guardModule($project, $module, 'manageWorkItems');

        $query = trim((string) $request->query('q', ''));
        $user = Auth::user();
        $linked = $module->workItems()->pluck('work_items.id')->all();

        $items = WorkItem::query()
            ->forProject($project->id)
            ->active()
            ->when($query !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('title', 'like', "%{$query}%")
                ->orWhere('identifier', 'like', "%{$query}%")))
            ->with('state')
            ->orderBy('sequence_no')
            ->limit(50)
            ->get()
            ->filter(fn (WorkItem $i) => $user->can('view', $i))
            ->map(fn (WorkItem $i) => [
                'id' => $i->id,
                'identifier' => $i->identifier,
                'title' => $i->title,
                'state' => $i->state ? ['name' => $i->state->name, 'color' => $i->state->color] : null,
                // §9.4: already linked items show as selected rather than being offered again.
                'linked' => in_array($i->id, $linked, true),
            ])->values()->all();

        return response()->json(['ok' => true, 'items' => $items]);
    }

    /**
     * The work items screen's payload, narrowed to one module.
     *
     * `seed` pre-fills the module so "Add work item" here adds work to it (§9.4). An archived
     * module seeds nothing — §15 refuses new work there.
     *
     * @return array<string, mixed>
     */
    private function moduleScreenPayload(Project $project, Module $module): array
    {
        $items = $module->workItems()
            ->active()
            ->with(['state', 'assignees', 'labels', 'parent:id,identifier,title', 'cycle', 'epic', 'estimateValue', 'modules', 'creator'])
            ->orderBy('sequence_no')
            ->get();

        return $this->payload->build($project, null, $items, $this->filters($project)) + [
            'seed' => $project->featureEnabled('modules') && ! $module->isArchived()
                ? ['module_ids' => [$module->id]]
                : [],
            'embedded' => true,
        ];
    }

    /** The Modules screen, as a list or focused on one module. */
    private function screen(Project $project, ?Module $pageModule = null): View
    {
        $modules = Module::query()
            ->forProject($project->id)
            ->with(['lead', 'members', 'creator'])
            ->orderByDesc('created_at')
            ->get();

        $progress = $this->progress->forModules($modules);

        return view('projects.modules', [
            'workspace' => Auth::user()->currentWorkspace,
            'user' => Auth::user(),
            'project' => $project,
            'tabs' => $this->navigation->tabs($project),
            'activeTab' => 'modules',
            'projects' => $this->navigation->sidebarProjects(Auth::user()),
            'canCreateProject' => Auth::user()->can('create', [WorkItem::class, $project]),
            'canManage' => Auth::user()->can('manage', $project),
            'bootstrap' => [
                'project' => [
                    'id' => $project->id,
                    'name' => $project->name,
                    'identifier' => $project->identifier,
                    'emoji' => $project->emoji,
                ],
                'modules' => $modules->map(fn (Module $m) => $this->card($m, $progress[$m->id] ?? null))->all(),
                // Set only on the per-module URL: render the detail, not the landing page.
                'pageModuleId' => $pageModule?->id,
                'items' => $pageModule ? $this->workItems($pageModule) : [],
                'states' => $project->states()->orderBy('position')->get()
                    ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'color' => $s->color, 'group' => $s->group])->all(),
                'members' => $this->workspaceMembers($project),
                'statuses' => collect(config('projects.module_statuses'))
                    ->map(fn (array $meta, string $key) => ['key' => $key] + $meta)->values()->all(),
                'defaultStatus' => config('projects.module_default_status'),
                // Feature Disable §3/§4: the page still loads when the feature is off so
                // existing records stay readable, and these tell the screen to render itself
                // read-only and say why. Every write ability is already false by then.
                'featureEnabled' => $project->featureEnabled('modules'),
                'disabledNotice' => $this->featureState->disabledNotice('modules'),
                'settingsUrl' => route('projects.settings', ['project' => $project->id, 'section' => 'modules']),
                // Project-Level Labels §3: the row's label chip follows the project's
                // setting, so a work item row reads the same here as on the work items list.
                // The detail's grid IS the work items screen, narrowed to this module.
                'workItems' => $pageModule ? $this->moduleScreenPayload($project, $pageModule) : null,
                'labelsEnabled' => $project->featureEnabled('labels'),
                'canCreate' => Auth::user()->can('create', [Module::class, $project]),
                'canDelete' => Auth::user()->can('manage', $project),
                'titleMax' => (int) config('projects.module_title_max'),
                'descriptionMax' => (int) config('projects.module_description_max'),
                // The empty state's clip. Null until a file is configured, which is why the
                // screen renders a labelled placeholder rather than a broken player.
                'introVideo' => config('projects.module_intro_video'),
                'endpoints' => [
                    'list' => route('projects.modules', $project),
                    'store' => route('projects.modules.store', $project),
                    'module' => route('projects.modules.show', ['project' => $project->id, 'module' => '__ID__']),
                    'update' => route('projects.modules.update', ['project' => $project->id, 'module' => '__ID__']),
                    'destroy' => route('projects.modules.destroy', ['project' => $project->id, 'module' => '__ID__']),
                    'archive' => route('projects.modules.archive', ['project' => $project->id, 'module' => '__ID__']),
                    'restore' => route('projects.modules.restore', ['project' => $project->id, 'module' => '__ID__']),
                    'addItems' => route('projects.modules.items.store', ['project' => $project->id, 'module' => '__ID__']),
                    'search' => route('projects.modules.search', ['project' => $project->id, 'module' => '__ID__']),
                    'removeItem' => route('projects.modules.items.destroy', [
                        'project' => $project->id, 'module' => '__ID__', 'workItem' => '__ITEM__',
                    ]),
                    'workItem' => route('projects.work-items.show', ['project' => $project->id, 'workItem' => '__ID__']),
                ],
            ],
        ]);
    }

    /**
     * The module must belong to THIS project — never trust the URL alone — and the user must
     * hold the ability.
     */
    private function guardModule(Project $project, Module $module, string $ability): void
    {
        abort_unless($module->project_id === $project->id, 404);
        abort_unless(Auth::user()->can($ability, $module), $ability === 'view' ? 404 : 403);
    }

    /** @return array<string, mixed> */
    private function card(Module $module, ?array $progress = null): array
    {
        $progress ??= $this->progress->forModule($module);
        $meta = $module->statusMeta();

        return [
            'id' => $module->id,
            'title' => $module->title,
            'description' => $module->description,
            'status' => $module->status,
            'status_label' => $meta['label'],
            'status_color' => $meta['color'],
            'start_date' => $module->start_date?->format('Y-m-d'),
            'end_date' => $module->end_date?->format('Y-m-d'),
            'archived' => $module->isArchived(),
            'progress' => $progress,
            'lead' => $module->lead ? $this->person($module->lead) : null,
            'members' => $module->members->map(fn (User $u) => $this->person($u))->values()->all(),
            'created_by' => $module->creator?->displayName(),
            'created_at' => $module->created_at?->toIso8601String(),
            'updated_at' => $module->updated_at?->toIso8601String(),
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

    /**
     * The work items in a module (§8.3), shaped exactly like the work item list's rows —
     * the grid that renders them is the same <wi-list> component.
     *
     * @return array<int, array<string, mixed>>
     */
    private function workItems(Module $module): array
    {
        $user = Auth::user();

        $items = $module->workItems()
            ->active()
            ->with(['state', 'assignees', 'labels'])
            ->orderBy('sequence_no')
            ->get()
            ->filter(fn (WorkItem $i) => $user->can('view', $i))
            ->values();

        $ids = $items->pluck('id')->all();
        $blocked = $this->blockers->counts($ids);
        $concerns = $this->statusUpdates->currentConcerns($ids);

        return $items->map(fn (WorkItem $i) => [
            'id' => $i->id,
            'identifier' => $i->identifier,
            'title' => $i->title,
            'priority' => $i->priority,
            'start_date' => $i->start_date?->format('Y-m-d'),
            'due_date' => $i->due_date?->format('Y-m-d'),
            'state' => $i->state ? [
                'id' => $i->state->id, 'name' => $i->state->name,
                'color' => $i->state->color, 'group' => $i->state->group,
            ] : null,
            'group' => $i->state?->group ?? 'backlog',
            'blocked_by_count' => $blocked[$i->id] ?? 0,
            'status_update' => $concerns[$i->id] ?? null,
            'assignees' => $i->assignees->map(fn (User $u) => $this->person($u))->values()->all(),
            'labels' => $i->labels->map(fn ($l) => ['id' => $l->id, 'name' => $l->name, 'color' => $l->color])->values()->all(),
        ])->all();
    }

    /**
     * Lead and member options. Active members of THIS workspace only (§15), so the picker
     * cannot leak users from another tenant.
     *
     * @return array<int, array<string, mixed>>
     */
    private function workspaceMembers(Project $project): array
    {
        return WorkspaceMembership::query()
            ->where('workspace_id', $project->tenant_id)
            ->where('status', WorkspaceMembership::STATUS_ACTIVE)
            ->with('user')
            ->get()
            ->filter(fn (WorkspaceMembership $m) => $m->user !== null)
            ->map(fn (WorkspaceMembership $m) => $this->person($m->user))
            ->values()->all();
    }

    /**
     * The filters this request is asking for (docs/features/filters.md).
     *
     * The same categories the Work Items screen offers, because this screen shows the same rows
     * through the same toolbar — a second definition here would be a second answer to what
     * "Status" means.
     */
    private function filters(Project $project): FilterSet
    {
        return FilterSet::fromRequest(
            request(),
            app(FilterRegistry::class)->workItems($project),
            $project,
        );
    }
}
