<?php

namespace Tests\Feature\Project;

use App\Services\WorkspaceCreator;
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
        $p = $this->makeProject($owner, $ws, ['name' => 'Testing', 'identifier' => 'TESTI']);
        $html = $this->actingAs($owner)->followingRedirects()->get(route('projects.show', $p->id))->assertOk()->getContent();
        $this->assertHasTopbar($html);
    }

    /**
     * The switcher is a modal on the current screen, not a trip to /welcome: every page
     * carrying the topbar ships the modal and its openers, and each other workspace is a
     * real POST form so switching works without JavaScript.
     */
    public function test_switch_workspace_opens_in_place_on_every_screen(): void
    {
        [$owner, $ws] = $this->owner();
        $second = app(WorkspaceCreator::class)->create($owner, [
            'name' => 'Blue Sky Agency', 'slug' => 'blue-sky', 'company_size' => '2-10',
        ]);
        // Creating a workspace makes it active; go back to the first one, which is where the
        // project lives — so the second is the one the switcher should offer.
        $owner->forceFill(['current_workspace_id' => $ws->id])->save();

        $project = $this->makeProject($owner->fresh(), $ws, ['name' => 'Testing', 'identifier' => 'TESTI']);

        $screens = [
            route('projects.index'),
            route('projects.work-items', $project->id),
            route('welcome'),
        ];

        foreach ($screens as $url) {
            $html = $this->actingAs($owner->fresh())->get($url)->assertOk()->getContent();

            $this->assertStringContainsString('data-ws-open', $html, "No switcher opener on {$url}");
            $this->assertStringContainsString('id="ws-modal"', $html, "No switcher modal on {$url}");
            // The other workspace is switchable right there — no redirect to /welcome first.
            $this->assertStringContainsString(
                'action="'.route('workspaces.switch', $second->id).'"', $html,
                "No switch form for the second workspace on {$url}",
            );
        }
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
        $p = $this->makeProject($owner, $ws, ['name' => 'Testing', 'identifier' => 'TESTI']);
        $html = $this->actingAs($owner)
            ->get(route('projects.settings', ['project' => $p->id, 'section' => 'general']))
            ->assertOk()->getContent();
        $this->assertStringNotContainsString('id="user-btn"', $html);
    }
}
