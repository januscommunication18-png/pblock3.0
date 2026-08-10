<?php

namespace Tests\Feature\Workspace;

use App\Models\OnboardingProfile;
use App\Models\User;
use App\Services\WorkspaceCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WelcomeTest extends TestCase
{
    use RefreshDatabase;

    public function test_welcome_redirects_to_onboarding_when_no_workspace(): void
    {
        $user = User::factory()->create();
        OnboardingProfile::create([
            'user_id' => $user->id,
            'current_step' => OnboardingProfile::STEP_COMPLETED,
            'completed_at' => now(),
        ]);

        $this->actingAs($user)->get(route('welcome'))
            ->assertRedirect(route('onboarding.workspace'));
    }

    public function test_welcome_renders_for_a_user_with_a_workspace(): void
    {
        $user = User::factory()->create(['full_name' => 'Rohit Philip', 'email' => 'rohit@example.com']);
        OnboardingProfile::create([
            'user_id' => $user->id,
            'current_step' => OnboardingProfile::STEP_COMPLETED,
            'completed_at' => now(),
            'workspace_setup_completed_at' => now(),
        ]);
        app(WorkspaceCreator::class)->create($user, [
            'name' => 'Acme Inc', 'slug' => 'acme-inc', 'company_size' => '2-10',
        ]);

        $this->actingAs($user->fresh())->get(route('welcome'))
            ->assertOk()
            ->assertSee('welcome aboard', false)
            ->assertSee('Acme Inc');
    }
}
