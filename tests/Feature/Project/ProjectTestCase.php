<?php

namespace Tests\Feature\Project;

use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\ProjectCreator;
use App\Services\WorkspaceCreator;
use Tests\TestCase;

/**
 * Shared setup for Phase 4 (Create Project) feature tests: an owner + their workspace, a
 * helper to add members with a given role, and a helper to create a project directly through
 * the service. Mirrors the Phase 3 SettingsTestCase pattern.
 */
abstract class ProjectTestCase extends TestCase
{
    /** @return array{0: User, 1: Workspace} owner (fresh) + their workspace */
    protected function owner(string $slug = 'acme-inc'): array
    {
        $user = User::factory()->create(['full_name' => 'Rohit', 'email' => "owner-{$slug}@example.com"]);
        $workspace = app(WorkspaceCreator::class)->create($user, [
            'name' => 'Acme Inc', 'slug' => $slug, 'company_size' => '2-10',
        ]);

        return [$user->fresh(), $workspace];
    }

    /** Add a member with a role and make the workspace their active one. Returns fresh user. */
    protected function member(Workspace $workspace, string $role, string $email): User
    {
        $user = User::factory()->create(['email' => $email]);
        WorkspaceMembership::create([
            'workspace_id' => $workspace->id, 'user_id' => $user->id,
            'role' => $role, 'status' => 'active', 'joined_at' => now(),
        ]);
        $user->forceFill(['current_workspace_id' => $workspace->id])->save();

        return $user->fresh();
    }

    /** Create a project directly via the service (bypassing the HTTP layer). */
    protected function makeProject(User $creator, Workspace $workspace, array $overrides = []): Project
    {
        return app(ProjectCreator::class)->create($creator, $workspace, array_merge([
            'name' => 'Website Redesign',
            'identifier' => 'WEB',
            'description' => null,
            'visibility' => Project::VISIBILITY_PUBLIC,
            'lead_user_id' => null,
        ], $overrides));
    }
}
