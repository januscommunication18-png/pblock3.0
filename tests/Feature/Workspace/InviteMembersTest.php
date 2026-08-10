<?php

namespace Tests\Feature\Workspace;

use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMembership;
use App\Services\WorkspaceCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InviteMembersTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): array
    {
        $user = User::factory()->create(['full_name' => 'Rohit', 'email' => 'rohit@example.com']);
        $workspace = app(WorkspaceCreator::class)->create($user, [
            'name' => 'Acme Inc', 'slug' => 'acme-inc', 'company_size' => '2-10',
        ]);

        return [$user->fresh(), $workspace];
    }

    public function test_invite_screen_renders_for_current_workspace(): void
    {
        [$user, $workspace] = $this->owner();

        $this->actingAs($user)->get(route('workspaces.invite'))
            ->assertOk()
            ->assertSee('Invite teammates')
            ->assertSee($workspace->name);
    }

    public function test_in_app_invites_are_persisted_and_return_to_welcome(): void
    {
        [$user, $workspace] = $this->owner();

        $this->actingAs($user)->post(route('workspaces.invite.store'), [
            'invites' => [['email' => 'new@company.com', 'role' => 'member']],
        ])->assertRedirect(route('welcome'));

        $this->assertSame(1, $workspace->run(fn () => WorkspaceInvitation::count()));
        $this->assertDatabaseHas('workspace_invitations', [
            'tenant_id' => $workspace->id, 'email' => 'new@company.com', 'status' => 'pending',
        ]);
    }

    public function test_cannot_invite_an_existing_member_again(): void
    {
        [$owner, $workspace] = $this->owner();

        $member = User::factory()->create(['email' => 'taken@company.com']);
        WorkspaceMembership::create([
            'workspace_id' => $workspace->id, 'user_id' => $member->id,
            'role' => 'member', 'status' => 'active', 'joined_at' => now(),
        ]);

        $this->actingAs($owner)->post(route('workspaces.invite.store'), [
            'invites' => [['email' => 'taken@company.com', 'role' => 'admin']],
        ])->assertSessionHasErrors('invites.0.email');

        $this->assertSame(0, $workspace->run(fn () => WorkspaceInvitation::count()));
    }

    public function test_cannot_invite_the_same_email_twice(): void
    {
        [$owner, $workspace] = $this->owner();

        // First invite succeeds.
        $this->actingAs($owner)->post(route('workspaces.invite.store'), [
            'invites' => [['email' => 'pending@company.com', 'role' => 'member']],
        ])->assertRedirect(route('welcome'));

        // Second invite for the same email is rejected with a per-row error.
        $this->actingAs($owner)->post(route('workspaces.invite.store'), [
            'invites' => [['email' => 'pending@company.com', 'role' => 'viewer']],
        ])->assertSessionHasErrors('invites.0.email');

        $this->assertSame(1, $workspace->run(fn () => WorkspaceInvitation::count()));
    }

    public function test_cannot_list_the_same_email_twice_in_one_submission(): void
    {
        [$owner] = $this->owner();

        $this->actingAs($owner)->post(route('workspaces.invite.store'), [
            'invites' => [
                ['email' => 'dupe@company.com', 'role' => 'member'],
                ['email' => 'dupe@company.com', 'role' => 'viewer'],
            ],
        ])->assertSessionHasErrors('invites.1.email');
    }

    public function test_non_admin_member_cannot_invite(): void
    {
        [$owner, $workspace] = $this->owner();

        $member = User::factory()->create(['email' => 'member@example.com']);
        WorkspaceMembership::create([
            'workspace_id' => $workspace->id, 'user_id' => $member->id,
            'role' => 'member', 'status' => 'active', 'joined_at' => now(),
        ]);
        $member->forceFill(['current_workspace_id' => $workspace->id])->save();

        $this->actingAs($member->fresh())->get(route('workspaces.invite'))->assertForbidden();
        $this->actingAs($member->fresh())->post(route('workspaces.invite.store'), [
            'invites' => [['email' => 'x@company.com', 'role' => 'member']],
        ])->assertForbidden();
    }
}
