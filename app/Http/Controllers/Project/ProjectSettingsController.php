<?php

namespace App\Http\Controllers\Project;

use App\Http\Requests\Project\UpdateProjectRequest;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\WorkspaceMembership;
use App\Services\ProjectFeatureState;
use DateTimeZone;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * Project Settings — section pages plus General, Features and cover mutations (PRJ-040/042).
 * States/Labels/Members mutations live in their own controllers; Estimates and Automations
 * are Coming Soon (PRJ-044).
 */
class ProjectSettingsController extends ManagesProjectController
{
    public function __construct(private readonly ProjectFeatureState $featureState) {}

    /** GET /projects/{project}/settings/{section} */
    public function show(Project $project, string $section): View
    {
        $this->guardManage($project);

        $item = collect(config('projects.settings_nav'))->firstWhere('key', $section);
        abort_if($item === null, 404);

        if (($item['status'] ?? '') === 'soon') {
            return $this->page($project, 'placeholder', ['label' => $item['label']]);
        }

        return $this->page($project, $section, $this->bootstrapFor($project, $section));
    }

    /** PATCH /projects/{project}/settings/general */
    public function updateGeneral(UpdateProjectRequest $request, Project $project): JsonResponse
    {
        $this->guardManage($project);

        $project->forceFill([
            'name' => $request->validated('name'),
            // `identifier` is deliberately absent: it cannot change after creation.
            'description' => $request->validated('description'),
            'visibility' => $request->validated('visibility'),
            'lead_user_id' => $request->validated('lead_user_id'),
            'timezone' => $request->validated('timezone'),
            'work_item_view' => $request->validated('work_item_view') ?? $project->work_item_view,
            'default_assignee_id' => $request->validated('default_assignee_id'),
        ])->save();

        $this->syncLead($project);

        // §13: replace the subscriber set outright — the form sends the full list, so a
        // removed member has to disappear rather than linger.
        if ($request->has('subscriber_ids')) {
            $project->subscribers()->sync(array_values(array_unique($request->validated('subscriber_ids') ?? [])));
        }

        return response()->json([
            'ok' => true,
            'message' => 'Project settings updated successfully.',
        ]);
    }

    /** POST /projects/{project}/settings/cover (PRJ-027). */
    public function uploadCover(Request $request, Project $project): JsonResponse
    {
        $this->guardManage($project);
        $request->validate([
            'cover' => ['required', 'file', 'mimes:'.implode(',', config('projects.cover.mimes')), 'max:'.(int) config('projects.cover.max_kb')],
        ]);

        $path = $request->file('cover')->store("project-covers/{$project->tenant_id}", 'public');
        $project->forceFill(['cover_url' => Storage::disk('public')->url($path)])->save();

        return response()->json(['ok' => true, 'cover_url' => $project->cover_url]);
    }

    /**
     * POST /projects/{project}/settings/features/toggle
     *
     * Both gates §3 describes are enforced HERE, not only in the UI (Cycles §13's closing
     * line): a hand-rolled POST is refused exactly as the disabled toggle implies.
     */
    public function toggleFeature(Request $request, Project $project): JsonResponse
    {
        $this->guardManage($project);

        $catalog = config('projects.features');
        $key = (string) $request->input('feature');
        abort_unless(array_key_exists($key, $catalog), 422, 'Unknown feature.');

        $enabling = $request->boolean('enabled');
        $features = $project->featureFlags();

        if ($enabling) {
            // Cycles §13: a paid feature the workspace's plan does not include.
            $entitlement = $catalog[$key]['entitlement'] ?? null;
            abort_if($entitlement && ! $project->entitledTo($entitlement), 422,
                "{$catalog[$key]['label']} is not included in your plan.");

            // Cycles §3.3.1: Parallel cycles only means anything with Cycles switched on.
            // An `if` rather than abort_if(): the message names the prerequisite, and PHP
            // builds every argument before the call — so `$catalog[null]` would be read on
            // every feature that has no prerequisite at all.
            $requires = $catalog[$key]['requires'] ?? null;
            if ($requires && ! ($features[$requires] ?? false)) {
                abort(422, "Turn on {$catalog[$requires]['label']} first.");
            }
        }

        // Feature Disable §7: switching Epics, Modules or Cycles off asks first, and the
        // dialog says exactly what survives. The gate is real, not a UI nicety — the client
        // sends `confirm` only after the user has seen it, so a hand-rolled POST cannot skip
        // the step. Nothing is deleted either way; this is about not being surprised.
        //
        // Only asked when there is something to preserve: confirming the disable of a feature
        // that never held a record is a dialog with nothing to say.
        if (! $enabling && ($catalog[$key]['confirm_disable'] ?? false) && ! $request->boolean('confirm')) {
            $confirmation = $this->featureState->disableConfirmation($project, $key);

            if ($confirmation['count'] > 0) {
                return response()->json(['ok' => false] + $confirmation, 409);
            }
        }

        $features[$key] = $enabling;

        // Switching a feature off switches off whatever depended on it, rather than leaving a
        // dependent flag stored as ON with nothing behind it — a state the UI would have no
        // honest way to render. Only the flags change; §3.2.4's data is untouched.
        if (! $enabling) {
            foreach ($catalog as $dependent => $meta) {
                if (($meta['requires'] ?? null) === $key) {
                    $features[$dependent] = false;
                }
            }
        }

        $project->forceFill(['features' => $features])->save();

        return response()->json(['ok' => true, 'features' => $project->featureFlags()]);
    }

    /** Ensure the (new) lead is a project member. */
    private function syncLead(Project $project): void
    {
        if ($project->lead_user_id) {
            ProjectMember::query()->firstOrCreate(
                ['project_id' => $project->id, 'user_id' => $project->lead_user_id],
                ['role' => ProjectMember::ROLE_CONTRIBUTOR],
            );
        }
    }

    /** @return array<string, mixed> */
    private function bootstrapFor(Project $project, string $section): array
    {
        return match ($section) {
            'general' => [
                'project' => [
                    'name' => $project->name, 'identifier' => $project->identifier,
                    'description' => $project->description, 'visibility' => $project->visibility,
                    'lead_user_id' => $project->lead_user_id, 'timezone' => $project->timezone,
                    'cover_url' => $project->cover_url, 'status' => $project->status,
                    // Shown on the cover banner alongside the name and ID (§4).
                    'emoji' => $project->emoji,
                    'work_item_view' => $project->work_item_view,
                    'default_assignee_id' => $project->default_assignee_id,
                    'subscriber_ids' => $project->subscribers()->pluck('users.id')->all(),
                    // §17: read-only, from the original creation timestamp.
                    'created_on' => optional($project->created_at)->format('M j, Y'),
                ],
                // §10's two options, described the way the spec words them.
                'workItemViews' => [
                    ['value' => 'all', 'label' => 'All work items',
                        'hint' => 'Project members can view all work items in this project based on their project permissions.'],
                    ['value' => 'assigned', 'label' => 'Assigned work items only',
                        'hint' => 'Members can only view work items assigned to them.'],
                ],
                'members' => $this->workspaceMembers($project),
                'visibilities' => config('projects.visibilities'),
                'timezones' => DateTimeZone::listIdentifiers(),
                'endpoints' => [
                    'update' => route('projects.settings.general.update', $project),
                    'cover' => route('projects.settings.cover', $project),
                    'archive' => route('projects.archive', $project),
                    'restore' => route('projects.restore', $project),
                    'delete' => route('projects.destroy', $project),
                ],
            ],
            'members' => [
                // Shared with the mutation endpoints so the screen and its responses always
                // describe members the same way (§6/§8).
                'members' => ProjectMembersController::memberList($project),
                'candidates' => ProjectMembersController::candidates($project),
                'roles' => config('projects.roles'),
                'defaultRole' => config('projects.default_role'),
                'workspaceRoles' => config('workspace.roles'),
                'canManage' => Auth::user()->can('manageMembers', $project),
                'endpoints' => [
                    'store' => route('projects.settings.members.store', $project),
                    'role' => route('projects.settings.members.role', ['project' => $project->id, 'member' => '__ID__']),
                    'remove' => route('projects.settings.members.remove', ['project' => $project->id, 'member' => '__ID__']),
                ],
            ],
            'features' => $this->featureSection($project, 'features',
                'Cycle',
                'Plan this project\'s work in time-boxed cycles. Turning cycles off hides them from the project without deleting a single cycle or its work items.'),

            'modules' => $this->featureSection($project, 'modules',
                'Module',
                'Group related work items into focused initiatives or delivery areas. Turning modules off hides them from the project without deleting a module or its work item links.'),
            // §3: one switch, so it reuses the shared feature-toggle screen — the same
            // control Cycles, Modules and Epics use, which also gives Pages §5's confirmation
            // and §6's restore-on-re-enable without a second implementation.
            'pages' => $this->featureSection($project, 'pages',
                'Pages',
                'Allow members of this project to create and manage project documentation using Pages. Turning pages off hides them from the project without deleting a page or its content.'),

            'epics' => $this->featureSection($project, 'epics',
                'Epic',
                'Organize work items into larger initiatives and track progress across multiple areas and cycles. Turning epics off hides them from the project without deleting an epic or its work item assignments.'),
            // §3: the Estimation screen owns its own shape — the toggle, the configured
            // system and its values — so it builds its payload rather than reusing the
            // generic feature-switch list.
            'estimates' => EstimationController::payload($project),
            'states' => [
                'states' => $project->states()->orderBy('position')->get()
                    ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'color' => $s->color, 'group' => $s->group, 'is_default' => $s->is_default])->all(),
                // The state-group vocabulary and the colour palette are defined once, in
                // config/settings.php. These screens referenced `projects.*` keys that were
                // never added, so the States section 500'd on array_keys(null) — one
                // definition, shared, rather than a second copy that can drift from it.
                'groups' => config('settings.state_groups'),
                'color' => ['presets' => config('settings.color_presets')],
                'endpoints' => [
                    'store' => route('projects.settings.states.store', $project),
                    'state' => route('projects.settings.states.update', ['project' => $project->id, 'state' => '__ID__']),
                ],
            ],
            'labels' => [
                // §2: the switch, and §6/§12/§24: the list it reveals, with usage counts.
                'enabled' => $project->featureEnabled('labels'),
                'labels' => $project->labels()->withCount('workItems')->orderBy('position')->get()
                    ->map(fn ($l) => [
                        'id' => $l->id, 'name' => $l->name, 'description' => $l->description,
                        'color' => $l->color, 'archived' => $l->isArchived(), 'usage' => $l->work_items_count,
                    ])->all(),
                'color' => ['presets' => config('settings.color_presets')],
                'nameMax' => (int) config('projects.label_name_max'),
                'descriptionMax' => (int) config('projects.label_description_max'),
                'endpoints' => [
                    'store' => route('projects.settings.labels.store', $project),
                    'label' => route('projects.settings.labels.update', ['project' => $project->id, 'label' => '__ID__']),
                    'archive' => route('projects.settings.labels.archive', ['project' => $project->id, 'label' => '__ID__']),
                    'toggle' => route('projects.settings.features.toggle', $project),
                ],
            ],
            default => [],
        };
    }

    /**
     * One feature-toggle screen's payload.
     *
     * Each screen shows only the features that name it in the catalog's `section`, so a new
     * feature lands somewhere deliberate rather than on whichever page happens to render the
     * whole catalog — which is exactly what put Modules on the Cycle page for a moment.
     *
     * @return array<string, mixed>
     */
    private function featureSection(Project $project, string $section, string $title, string $description): array
    {
        return [
            'title' => $title,
            'description' => $description,
            'features' => $project->featureFlags(),
            // The catalog travels with each entry's plan verdict resolved, so the screen
            // renders Upgrade from the same answer the server enforces (Cycles §13).
            'catalog' => collect(config('projects.features'))
                ->filter(fn (array $meta) => ($meta['section'] ?? 'features') === $section)
                ->map(fn (array $meta) => $meta + [
                    'requires' => $meta['requires'] ?? null,
                    'entitled' => ! isset($meta['entitlement']) || $project->entitledTo($meta['entitlement']),
                ])->all(),
            'endpoints' => ['toggle' => route('projects.settings.features.toggle', $project)],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function workspaceMembers(Project $project): array
    {
        return WorkspaceMembership::query()
            ->where('workspace_id', $project->tenant_id)
            ->where('status', WorkspaceMembership::STATUS_ACTIVE)
            ->with('user')
            ->get()
            ->map(fn (WorkspaceMembership $m) => [
                'id' => $m->user_id, 'name' => $m->user?->displayName(),
                'email' => $m->user?->email, 'initial' => $m->user?->initial(),
                // The subscriber chips show faces, so the photo has to travel with them.
                'avatar_url' => $m->user?->avatar_url,
            ])->all();
    }
}
