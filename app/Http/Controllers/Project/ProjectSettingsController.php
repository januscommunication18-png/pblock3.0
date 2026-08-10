<?php

namespace App\Http\Controllers\Project;

use App\Http\Requests\Project\UpdateProjectRequest;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\WorkspaceMembership;
use DateTimeZone;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Project Settings — section pages plus General, Features and cover mutations (PRJ-040/042).
 * States/Labels/Members mutations live in their own controllers; Estimates and Automations
 * are Coming Soon (PRJ-044).
 */
class ProjectSettingsController extends ManagesProjectController
{
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
            'identifier' => strtoupper($request->validated('identifier')),
            'description' => $request->validated('description'),
            'visibility' => $request->validated('visibility'),
            'lead_user_id' => $request->validated('lead_user_id'),
            'timezone' => $request->validated('timezone'),
        ])->save();

        $this->syncLead($project);

        return response()->json(['ok' => true]);
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

    /** POST /projects/{project}/settings/features/toggle */
    public function toggleFeature(Request $request, Project $project): JsonResponse
    {
        $this->guardManage($project);
        $key = (string) $request->input('feature');
        abort_unless(array_key_exists($key, config('projects.features')), 422, 'Unknown feature.');

        $features = $project->featureFlags();
        $features[$key] = $request->boolean('enabled');
        $project->forceFill(['features' => $features])->save();

        return response()->json(['ok' => true, 'features' => $project->featureFlags()]);
    }

    /** Ensure the (new) lead is a project member. */
    private function syncLead(Project $project): void
    {
        if ($project->lead_user_id) {
            ProjectMember::query()->firstOrCreate(
                ['project_id' => $project->id, 'user_id' => $project->lead_user_id],
                ['role' => ProjectMember::ROLE_MEMBER],
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
                'members' => $this->projectMembers($project),
                'candidates' => $this->workspaceMembers($project),
                'roles' => config('projects.roles'),
                'endpoints' => [
                    'store' => route('projects.settings.members.store', $project),
                    'role' => route('projects.settings.members.role', ['project' => $project->id, 'member' => '__ID__']),
                    'remove' => route('projects.settings.members.remove', ['project' => $project->id, 'member' => '__ID__']),
                ],
            ],
            'features' => [
                'features' => $project->featureFlags(),
                'catalog' => config('projects.features'),
                'endpoints' => ['toggle' => route('projects.settings.features.toggle', $project)],
            ],
            'states' => [
                'states' => $project->states()->orderBy('position')->get()
                    ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'color' => $s->color, 'group' => $s->group, 'is_default' => $s->is_default])->all(),
                'groups' => config('projects.state_groups'),
                'color' => ['presets' => config('projects.color_presets')],
                'endpoints' => [
                    'store' => route('projects.settings.states.store', $project),
                    'state' => route('projects.settings.states.update', ['project' => $project->id, 'state' => '__ID__']),
                ],
            ],
            'labels' => [
                'labels' => $project->labels()->orderBy('position')->get()
                    ->map(fn ($l) => ['id' => $l->id, 'name' => $l->name, 'color' => $l->color])->all(),
                'color' => ['presets' => config('projects.color_presets')],
                'endpoints' => [
                    'store' => route('projects.settings.labels.store', $project),
                    'label' => route('projects.settings.labels.update', ['project' => $project->id, 'label' => '__ID__']),
                ],
            ],
            default => [],
        };
    }

    /** @return array<int, array<string, mixed>> */
    private function projectMembers(Project $project): array
    {
        return $project->members()->with('user')->get()
            ->map(fn (ProjectMember $m) => [
                'id' => $m->id, 'user_id' => $m->user_id,
                'name' => $m->user?->displayName(), 'email' => $m->user?->email,
                'initial' => $m->user?->initial(), 'role' => $m->role,
                'is_lead' => $m->user_id === $project->lead_user_id,
            ])->all();
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
            ])->all();
    }
}
