<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/** Access control + placeholder/coming-soon sections (spec §3, §12, §13). */
class SettingsAccessTest extends SettingsTestCase
{
    use RefreshDatabase;

    public function test_owner_can_open_every_active_section(): void
    {
        [$owner] = $this->owner();

        foreach (['general', 'members', 'projects', 'wiki', 'releases', 'initiatives', 'teamspaces', 'customers'] as $section) {
            $this->actingAs($owner)->get("/settings/{$section}")->assertOk();
        }
    }

    public function test_admin_can_manage_but_member_viewer_guest_cannot(): void
    {
        [, $workspace] = $this->owner();

        $admin = $this->member($workspace, 'admin', 'admin@example.com');
        $this->actingAs($admin)->get(route('settings.general'))->assertOk();

        foreach (['member', 'viewer', 'guest'] as $role) {
            $user = $this->member($workspace, $role, "{$role}@example.com");
            $this->actingAs($user)->get(route('settings.general'))->assertForbidden();
            $this->actingAs($user)->postJson(route('settings.projects.toggle'), ['enabled' => false])->assertForbidden();
        }
    }

    public function test_user_without_workspace_is_redirected_to_onboarding(): void
    {
        $stray = User::factory()->create(['current_workspace_id' => null]);

        $this->actingAs($stray)->get('/settings/general')->assertRedirect(route('onboarding.workspace'));
    }

    public function test_coming_soon_and_placeholder_sections_render_without_functionality(): void
    {
        [$owner] = $this->owner();

        $this->actingAs($owner)->get('/settings/templates')->assertOk()->assertSee('Coming soon');
        $this->actingAs($owner)->get('/settings/integrations')->assertOk()->assertSee('Coming soon');
        $this->actingAs($owner)->get('/settings/billing')->assertOk();
        $this->actingAs($owner)->get('/settings/webhooks')->assertOk();
    }

    public function test_unknown_section_404s(): void
    {
        [$owner] = $this->owner();

        $this->actingAs($owner)->get('/settings/does-not-exist')->assertNotFound();
    }
}
