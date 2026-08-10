<?php

namespace Tests\Feature\Workspace;

use App\Models\OnboardingProfile;
use App\Models\User;
use App\Services\WorkspaceCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The workspace-creation flows show the "Enable apps" multi-select (Projects + Coming Soon). */
class WorkspaceAppsTest extends TestCase
{
    use RefreshDatabase;

    private function onboardedUser(): User
    {
        $user = User::factory()->create(['full_name' => 'Rohit', 'email' => 'rohit@example.com']);
        OnboardingProfile::create([
            'user_id' => $user->id,
            'current_step' => OnboardingProfile::STEP_COMPLETED,
            'completed_at' => now(),
        ]);

        return $user;
    }

    private function assertAppsSection($response): void
    {
        $response->assertSee('Enable apps')
            ->assertSee('Projects')
            ->assertSee('Default')           // Projects is the default, read-only app
            ->assertSee('Wiki')
            ->assertSee('Help Desk')
            ->assertSee('Client Hub')
            ->assertSee('Coming soon', false)
            ->assertSee('Plan projects, tasks, milestones', false);
    }

    public function test_onboarding_workspace_screen_shows_app_selector(): void
    {
        $this->assertAppsSection(
            $this->actingAs($this->onboardedUser())->get(route('onboarding.workspace'))->assertOk()
        );
    }

    public function test_additional_workspace_screen_shows_app_selector(): void
    {
        $user = $this->onboardedUser();
        app(WorkspaceCreator::class)->create($user, ['name' => 'First WS', 'slug' => 'first-ws', 'company_size' => '2-10']);

        $this->assertAppsSection(
            $this->actingAs($user->fresh())->get(route('workspaces.create'))->assertOk()
        );
    }
}
