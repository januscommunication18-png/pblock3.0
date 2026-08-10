<?php

namespace App\Http\Controllers\Project;

use App\Models\Project;
use App\Models\ProjectMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Project → Settings → Members (PRJ-041). Add/remove members and set project roles, within
 * workspace constraints (a candidate must already be a workspace member). The final project
 * admin is protected from demotion/removal.
 */
class ProjectMembersController extends ManagesProjectController
{
    /** POST /projects/{project}/settings/members */
    public function store(Request $request, Project $project): JsonResponse
    {
        $this->guardManage($project);

        $data = $request->validate([
            'user_id' => [
                'required', 'integer',
                Rule::exists('workspace_memberships', 'user_id')
                    ->where(fn ($q) => $q->where('workspace_id', $project->tenant_id)->where('status', 'active')),
            ],
            'role' => ['required', Rule::in(array_keys(config('projects.roles')))],
        ]);

        ProjectMember::query()->updateOrCreate(
            ['project_id' => $project->id, 'user_id' => $data['user_id']],
            ['role' => $data['role']],
        );

        return response()->json(['ok' => true, 'members' => $this->members($project)]);
    }

    /** PATCH /projects/{project}/settings/members/{member}/role */
    public function updateRole(Request $request, Project $project, ProjectMember $member): JsonResponse
    {
        $this->guardManage($project);
        $this->assertBelongs($project, $member);
        $data = $request->validate(['role' => ['required', Rule::in(array_keys(config('projects.roles')))]]);

        // Don't demote the last remaining admin.
        if ($member->isAdmin() && $data['role'] !== ProjectMember::ROLE_ADMIN) {
            abort_if($this->adminCount($project) <= 1, 422, 'A project must keep at least one admin.');
        }

        $member->forceFill(['role' => $data['role']])->save();

        return response()->json(['ok' => true, 'members' => $this->members($project)]);
    }

    /** DELETE /projects/{project}/settings/members/{member} */
    public function remove(Project $project, ProjectMember $member): JsonResponse
    {
        $this->guardManage($project);
        $this->assertBelongs($project, $member);

        abort_if($member->isAdmin() && $this->adminCount($project) <= 1, 422, 'A project must keep at least one admin.');

        // If the removed member was the project lead, clear the lead.
        if ($member->user_id === $project->lead_user_id) {
            $project->forceFill(['lead_user_id' => null])->save();
        }
        $member->delete();

        return response()->json(['ok' => true, 'members' => $this->members($project)]);
    }

    private function assertBelongs(Project $project, ProjectMember $member): void
    {
        abort_unless($member->project_id === $project->id, 404);
    }

    private function adminCount(Project $project): int
    {
        return ProjectMember::query()->where('project_id', $project->id)
            ->where('role', ProjectMember::ROLE_ADMIN)->count();
    }

    /** @return array<int, array<string, mixed>> */
    private function members(Project $project): array
    {
        return $project->members()->with('user')->get()
            ->map(fn (ProjectMember $m) => [
                'id' => $m->id, 'user_id' => $m->user_id,
                'name' => $m->user?->displayName(), 'email' => $m->user?->email,
                'initial' => $m->user?->initial(), 'role' => $m->role,
                'is_lead' => $m->user_id === $project->lead_user_id,
            ])->all();
    }
}
