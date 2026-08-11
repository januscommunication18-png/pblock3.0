<?php

namespace App\Http\Controllers\Project;

use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\WorkspaceMembership;
use App\Services\ProjectMemberManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Project → Settings → Members (Project Member Management, whole document).
 *
 * Authorization is §17: Workspace Owner, Workspace Admin, or this project's own Admin. §24
 * is explicit that hiding the controls in the UI is not sufficient, so every action here
 * re-checks `manageMembers` server-side, and §28's checklist (workspace containment, project
 * ownership of the member row, valid role) is enforced in ProjectMemberManager.
 */
class ProjectMembersController extends ManagesProjectController
{
    public function __construct(private readonly ProjectMemberManager $members) {}

    /** POST /projects/{project}/settings/members — add a coworker (§11). */
    public function store(Request $request, Project $project): JsonResponse
    {
        $this->guardMembers($project);

        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'role' => ['required', Rule::in(array_keys(config('projects.roles')))],
        ]);

        // Eligibility (§26) and duplicate rejection (§25) live in the service, so they hold
        // for every caller rather than only this endpoint.
        $this->members->add($project, (int) $data['user_id'], $data['role'], Auth::user());

        return $this->listResponse($project, 'Member added to project successfully.');
    }

    /** PATCH /projects/{project}/settings/members/{member}/role — change a role (§13). */
    public function updateRole(Request $request, Project $project, ProjectMember $member): JsonResponse
    {
        $this->guardMembers($project);
        $this->assertBelongs($project, $member);

        $data = $request->validate([
            'role' => ['required', Rule::in(array_keys(config('projects.roles')))],
        ]);

        $this->members->updateRole($project, $member, $data['role'], Auth::user());

        return $this->listResponse($project, 'Project role updated successfully.');
    }

    /** DELETE /projects/{project}/settings/members/{member} — remove from project (§14). */
    public function remove(Project $project, ProjectMember $member): JsonResponse
    {
        $this->guardMembers($project);
        $this->assertBelongs($project, $member);

        $this->members->remove($project, $member, Auth::user());

        return $this->listResponse($project, 'Member removed from project.');
    }

    /**
     * §17 authorization. Separate from `guardManage()` so "who can manage members" can
     * diverge from "who can edit project settings" without touching call sites.
     */
    private function guardMembers(Project $project): void
    {
        abort_unless(Auth::user()->can('manageMembers', $project), 403);
    }

    private function assertBelongs(Project $project, ProjectMember $member): void
    {
        // §28: the member row must belong to this project — never trust the URL alone.
        abort_unless($member->project_id === $project->id, 404);
    }

    private function listResponse(Project $project, string $message): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'message' => $message,
            'members' => static::memberList($project),
            'candidates' => static::candidates($project),
        ]);
    }

    /**
     * The project's members, with both role layers (§6).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function memberList(Project $project): array
    {
        $workspaceRoles = WorkspaceMembership::query()
            ->where('workspace_id', $project->tenant_id)
            ->pluck('role', 'user_id');

        return ProjectMember::query()
            ->where('project_id', $project->id)
            ->with(['user', 'addedBy'])
            ->get()
            ->map(fn (ProjectMember $m) => [
                'id' => $m->id,
                'user_id' => $m->user_id,
                'name' => $m->user?->displayName(),
                'email' => $m->user?->email,
                'initial' => $m->user?->initial(),
                'workspace_role' => $workspaceRoles[$m->user_id] ?? null,
                'role' => $m->role,
                'added_by' => $m->addedBy?->displayName(),
                'added_at' => $m->created_at?->format('Y-m-d'),
                'is_lead' => $m->user_id === $project->lead_user_id,
            ])
            ->sortBy('name')->values()->all();
    }

    /**
     * Workspace coworkers eligible to be added (§8/§9): active workspace members only,
     * excluding anyone already on the project.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function candidates(Project $project): array
    {
        $existing = ProjectMember::query()->where('project_id', $project->id)->pluck('user_id');

        return WorkspaceMembership::query()
            ->where('workspace_id', $project->tenant_id)
            ->where('status', WorkspaceMembership::STATUS_ACTIVE)
            ->whereNotIn('user_id', $existing)
            ->with('user')
            ->get()
            ->map(fn (WorkspaceMembership $m) => [
                'id' => $m->user_id,
                'name' => $m->user?->displayName(),
                'email' => $m->user?->email,
                'initial' => $m->user?->initial(),
                'workspace_role' => $m->role,
            ])
            ->sortBy('name')->values()->all();
    }
}
