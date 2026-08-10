<?php

namespace Tests\Feature\Workspace;

use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Services\WorkspaceCreator;
use App\Services\WorkspaceInviter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_invitations_are_scoped_to_their_workspace(): void
    {
        $owner = User::factory()->create(['email' => 'owner@example.com']);

        $wsA = app(WorkspaceCreator::class)->create($owner, [
            'name' => 'Alpha', 'slug' => 'alpha', 'company_size' => '2-10',
        ]);
        $wsB = app(WorkspaceCreator::class)->create($owner, [
            'name' => 'Beta', 'slug' => 'beta', 'company_size' => '2-10',
        ]);

        $inviter = app(WorkspaceInviter::class);
        $inviter->invite($wsA, $owner, [['email' => 'a1@x.com', 'role' => 'member']]);
        $inviter->invite($wsB, $owner, [
            ['email' => 'b1@x.com', 'role' => 'member'],
            ['email' => 'b2@x.com', 'role' => 'viewer'],
        ]);

        // Within tenant A, only A's invite is visible via the global TenantScope.
        $this->assertSame(1, $wsA->run(fn () => WorkspaceInvitation::count()));
        $this->assertSame('a1@x.com', $wsA->run(fn () => WorkspaceInvitation::first()->email));

        // Within tenant B, only B's two invites are visible.
        $this->assertSame(2, $wsB->run(fn () => WorkspaceInvitation::count()));

        // Central context (no tenancy) sees all rows; withoutTenancy is explicit.
        $this->assertSame(3, WorkspaceInvitation::count());
    }

    public function test_new_invitation_inherits_the_active_tenant_id(): void
    {
        $owner = User::factory()->create();
        $ws = app(WorkspaceCreator::class)->create($owner, [
            'name' => 'Gamma', 'slug' => 'gamma', 'company_size' => '2-10',
        ]);

        $ws->run(function () {
            WorkspaceInvitation::create([
                'email' => 'auto@x.com', 'role' => 'member',
                'token' => hash('sha256', 'seed'), 'status' => 'pending',
            ]);
        });

        $this->assertDatabaseHas('workspace_invitations', [
            'email' => 'auto@x.com', 'tenant_id' => $ws->id,
        ]);
    }
}
