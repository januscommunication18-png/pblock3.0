<?php

namespace Tests\Feature\Workspace;

use App\Models\OnboardingProfile;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceApps;
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
            ->assertSee('Help Center')
            ->assertSee('Client Hub')
            ->assertSee('Coming soon', false)
            ->assertSee('Plan projects, tasks, milestones', false);
    }

    /**
     * The section is rendered ONCE, from the shared partial, and each app appears once in it.
     *
     * This is a regression guard with a specific bug behind it: `workspace/create.blade.php`
     * kept a hard-coded copy of the apps markup below the `@include` of the partial, so the
     * step rendered twice — and the stale copy rendered every available app as a locked
     * "Default" with a hidden `apps[]` input. Wiki and the Help Center were therefore submitted as
     * ON whatever the real checkboxes above them said, which no `assertSee` would notice.
     */
    private function assertRenderedOnce($response): void
    {
        $html = $response->getContent();

        $this->assertSame(1, substr_count($html, 'Enable apps'), 'The apps step is rendered more than once.');

        foreach (['wiki', 'helpdesk'] as $key) {
            $this->assertSame(
                1,
                substr_count($html, 'name="apps[]" value="'.$key.'"'),
                "The [{$key}] app appears more than once in the form.",
            );
            // And as a CHOICE. A hidden input would submit it whatever the reader chose.
            $this->assertStringContainsString('type="checkbox" name="apps[]" value="'.$key.'"', $html);
        }

        // Projects is the one app that IS a hidden input — it is what a workspace is (WIKI-D3).
        $this->assertSame(1, substr_count($html, 'name="apps[]" value="projects"'));
    }

    public function test_onboarding_workspace_screen_shows_app_selector(): void
    {
        $response = $this->actingAs($this->onboardedUser())->get(route('onboarding.workspace'))->assertOk();

        $this->assertAppsSection($response);
        $this->assertRenderedOnce($response);
    }

    public function test_additional_workspace_screen_shows_app_selector(): void
    {
        $user = $this->onboardedUser();
        app(WorkspaceCreator::class)->create($user, ['name' => 'First WS', 'slug' => 'first-ws', 'company_size' => '2-10']);

        $response = $this->actingAs($user->fresh())->get(route('workspaces.create'))->assertOk();

        $this->assertAppsSection($response);
        $this->assertRenderedOnce($response);
    }

    /**
     * Unticking an app on the create form actually leaves it off.
     *
     * The end-to-end version of the bug above: while the duplicate section was there, every
     * available app went up with the form as a hidden input, so this workspace would have come
     * back with Wiki and the Help Center enabled.
     */
    public function test_an_app_left_unticked_is_not_enabled(): void
    {
        $user = $this->onboardedUser();
        app(WorkspaceCreator::class)->create($user, ['name' => 'First WS', 'slug' => 'first-ws', 'company_size' => '2-10']);

        $this->actingAs($user->fresh())->post(route('workspaces.store'), [
            'name' => 'Second WS',
            'slug' => 'second-ws',
            'team_size' => '2-10',
            'view_type' => 'agile',
            'apps' => ['projects', 'wiki'],
        ])->assertRedirect();

        $workspace = Workspace::query()->where('slug', 'second-ws')->firstOrFail();
        $apps = app(WorkspaceApps::class);

        $this->assertTrue($apps->isEnabled($workspace, 'wiki'));
        $this->assertFalse($apps->isEnabled($workspace, 'helpdesk'));
    }
}
