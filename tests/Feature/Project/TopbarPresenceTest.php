<?php

namespace Tests\Feature\Project;

use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The full application topbar (search + Get started + Workspace + Inbox/Help +
 * account menu) must render on every main app page, not just the home screen.
 */
class TopbarPresenceTest extends ProjectTestCase
{
    use RefreshDatabase;

    /** @return array<int, string> */
    private function topbarMarkers(): array
    {
        return ['id="user-btn"', 'placeholder="Search"', '>Get started<', '>Switch workspace<', 'title="Inbox"', 'title="Help"'];
    }

    private function assertHasTopbar(string $html): void
    {
        foreach ($this->topbarMarkers() as $needle) {
            $this->assertStringContainsString($needle, $html, "Topbar marker missing: {$needle}");
        }
    }

    public function test_projects_index_has_full_topbar(): void
    {
        [$owner] = $this->owner();
        $html = $this->actingAs($owner)->get(route('projects.index'))->assertOk()->getContent();
        $this->assertHasTopbar($html);
    }

    public function test_project_show_has_full_topbar(): void
    {
        [$owner, $ws] = $this->owner();
        $p = $this->makeProject($ws, $owner, ['name' => 'Testing', 'identifier' => 'TESTI']);
        $html = $this->actingAs($owner)->get(route('projects.show', $p->id))->assertOk()->getContent();
        $this->assertHasTopbar($html);
    }

    public function test_workspace_settings_uses_minimal_settings_header(): void
    {
        // Settings runs in its own "settings mode" shell — NOT the full app topbar.
        [$owner] = $this->owner();
        $html = $this->actingAs($owner)->get(route('settings.general'))->assertOk()->getContent();
        $this->assertStringNotContainsString('id="user-btn"', $html);
        $this->assertStringContainsString('Close settings', $html);
    }

    public function test_project_settings_uses_minimal_settings_header(): void
    {
        [$owner, $ws] = $this->owner();
        $p = $this->makeProject($ws, $owner, ['name' => 'Testing', 'identifier' => 'TESTI']);
        $html = $this->actingAs($owner)
            ->get(route('projects.settings', ['project' => $p->id, 'section' => 'general']))
            ->assertOk()->getContent();
        $this->assertStringNotContainsString('id="user-btn"', $html);
    }
}
