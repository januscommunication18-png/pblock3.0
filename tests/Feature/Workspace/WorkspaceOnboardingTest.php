<?php

namespace Tests\Feature\Workspace;

use App\Models\OnboardingProfile;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceOnboardingTest extends TestCase
{
    use RefreshDatabase;

    /** A user who has finished profile/role/goals personalization. */
    private function onboardedUser(): User
    {
        $user = User::factory()->create([
            'full_name' => 'Rohit Philip',
            'email' => 'rohit@example.com',
        ]);

        OnboardingProfile::create([
            'user_id' => $user->id,
            'current_step' => OnboardingProfile::STEP_COMPLETED,
            'completed_at' => now(),
        ]);

        return $user;
    }

    public function test_onboarded_user_with_no_workspace_sees_create_screen(): void
    {
        $this->actingAs($this->onboardedUser())
            ->get(route('onboarding.workspace'))
            ->assertOk()
            ->assertSee('Create your workspace');
    }

    public function test_first_workspace_is_created_as_agile_with_owner_membership(): void
    {
        $user = $this->onboardedUser();

        $response = $this->actingAs($user)->post(route('onboarding.workspace.store'), [
            'name' => 'Acme Inc',
            'slug' => 'acme-inc',
            'team_size' => '2-10',
        ]);

        $response->assertRedirect(route('onboarding.invite'));

        $workspace = Workspace::where('slug', 'acme-inc')->firstOrFail();
        $this->assertSame('Acme Inc', $workspace->name);
        $this->assertSame('agile', $workspace->view_type); // WS-VIEW-004
        $this->assertSame($user->id, $workspace->created_by);
        $this->assertSame(36, strlen($workspace->id)); // UUID internal key (spec §8)

        // WS-008: creator is Owner
        $this->assertDatabaseHas('workspace_memberships', [
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => WorkspaceMembership::ROLE_OWNER,
            'status' => 'active',
        ]);

        // WS-009: active workspace seeded
        $this->assertSame($workspace->id, $user->fresh()->current_workspace_id);
    }

    public function test_name_and_team_size_are_required(): void
    {
        $this->actingAs($this->onboardedUser())
            ->post(route('onboarding.workspace.store'), ['name' => '', 'slug' => '', 'team_size' => ''])
            ->assertSessionHasErrors(['name', 'slug', 'team_size']);

        $this->assertDatabaseCount('tenants', 0);
    }

    public function test_slug_must_be_unique(): void
    {
        Workspace::factory()->create(['slug' => 'taken']);

        $this->actingAs($this->onboardedUser())
            ->post(route('onboarding.workspace.store'), [
                'name' => 'Another', 'slug' => 'taken', 'team_size' => '2-10',
            ])
            ->assertSessionHasErrors('slug');
    }

    public function test_reserved_slug_is_blocked(): void
    {
        $this->actingAs($this->onboardedUser())
            ->post(route('onboarding.workspace.store'), [
                'name' => 'Admin Team', 'slug' => 'admin', 'team_size' => '2-10',
            ])
            ->assertSessionHasErrors('slug');
    }

    public function test_invalid_team_size_is_rejected(): void
    {
        $this->actingAs($this->onboardedUser())
            ->post(route('onboarding.workspace.store'), [
                'name' => 'Acme', 'slug' => 'acme', 'team_size' => '9000',
            ])
            ->assertSessionHasErrors('team_size');
    }

    public function test_creating_a_workspace_when_one_exists_redirects_forward(): void
    {
        $user = $this->onboardedUser();
        $this->actingAs($user)->post(route('onboarding.workspace.store'), [
            'name' => 'First', 'slug' => 'first-ws', 'team_size' => 'Just myself',
        ]);

        // Revisiting the create screen should not offer a second first-workspace.
        $this->actingAs($user->fresh())
            ->get(route('onboarding.workspace'))
            ->assertRedirect(route('onboarding.invite'));
    }
}
