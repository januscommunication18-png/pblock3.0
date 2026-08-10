<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\WorkspaceCreator;
use Tests\TestCase;

/**
 * Shared setup for Workspace Settings feature tests: an owner + their workspace, and
 * helpers to add members with a given role. Mirrors the Phase 3 workspace test pattern.
 */
abstract class SettingsTestCase extends TestCase
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
}
