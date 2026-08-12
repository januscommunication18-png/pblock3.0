<?php

namespace Tests\Feature\Project;

use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The shared app sidebar — its header controls, and the collapse that has to survive a
 * navigation.
 *
 * Worth pinning because the sidebar is a partial included by every app shell: it has no
 * screen of its own, so nothing else fails when a control is edited out of it.
 */
class AppSidebarTest extends ProjectTestCase
{
    use RefreshDatabase;

    public function test_the_sidebar_header_offers_settings_and_collapse(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);

        $html = $this->actingAs($owner)->get(route('projects.work-items', $project))
            ->assertOk()->getContent();

        // Settings goes to the workspace's own settings, the same place the rail's Settings
        // lands — one destination, two ways in.
        $this->assertStringContainsString('href="'.e(route('settings.general')).'"', $html);
        $this->assertStringContainsString('aria-label="Workspace settings"', $html);

        // Collapse, and the control that brings the panel back — which sits at the head of
        // the page's own title row, not in the topbar.
        $this->assertStringContainsString('id="collapse-sidebar"', $html);
        $this->assertStringContainsString('aria-label="Collapse sidebar"', $html);
        $this->assertStringContainsString('data-sidebar-expand', $html);
    }

    public function test_the_expand_control_sits_before_the_page_title(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['name' => 'Website Redesign']);

        $html = $this->actingAs($owner)->get(route('projects.work-items', $project))
            ->assertOk()->getContent();

        // It heads the title row — before the back link, the emoji and the project name.
        // Compared against the row's own back link rather than the project name, because the
        // name also appears in the document <title> far earlier in the response.
        $expand = strpos($html, 'data-sidebar-expand');
        $headerRow = strpos($html, 'Back to projects');

        $this->assertNotFalse($expand, 'No way to expand a collapsed sidebar on this screen.');
        $this->assertNotFalse($headerRow);
        $this->assertLessThan($headerRow, $expand);

        // The divider sits between the control and the page's own title, and ships with the
        // control so an expanded sidebar never leaves a stray rule at the start of the row.
        $divider = strpos($html, 'data-sidebar-divider');
        $this->assertNotFalse($divider);
        $this->assertGreaterThan($expand, $divider);
        $this->assertLessThan($headerRow, $divider);
    }

    public function test_the_collapsed_state_is_restored_before_the_sidebar_renders(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);

        $html = $this->actingAs($owner)->get(route('projects.work-items', $project))
            ->assertOk()->getContent();

        // The restore script has to come BEFORE the sidebar markup, or a collapsed panel
        // paints and then disappears on every page load.
        $restore = strpos($html, "localStorage.getItem('pb.sidebar.collapsed')");
        $sidebar = strpos($html, 'id="sidebar"');

        $this->assertNotFalse($restore, 'The collapsed sidebar is never restored.');
        $this->assertNotFalse($sidebar);
        $this->assertLessThan($sidebar, $restore, 'The restore script must run before the sidebar is parsed.');
    }

    public function test_every_app_screen_carries_the_same_sidebar_controls(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);

        $this->actingAs($owner)->postJson(route('projects.settings.features.toggle', $project), [
            'feature' => 'cycles', 'enabled' => true,
        ])->assertOk();

        // Every screen that RENDERS the collapsible sidebar needs a way back, or collapsing
        // it strands the user there. (Project Settings is absent on purpose: it has its own
        // nav and no app sidebar, so a control there would do nothing.)
        foreach ([
            route('projects.work-items', $project),
            route('projects.cycles', $project),
            route('projects.workspace.tab', ['project' => $project->id, 'tab' => 'overview']),
        ] as $url) {
            $html = $this->actingAs($owner)->get($url)->assertOk()->getContent();
            $this->assertStringContainsString('id="collapse-sidebar"', $html, "Missing collapse control on {$url}");
            $this->assertStringContainsString('data-sidebar-expand', $html, "No way back from a collapsed sidebar on {$url}");
        }

        // The projects index renders its header in Vue, so its expand control ships in the
        // screen script rather than a Blade partial — the delegated listener treats both the
        // same. Assert it is really there, since nothing else would notice if it went.
        $this->assertStringContainsString('data-sidebar-expand',
            (string) file_get_contents(public_path('assets/js/projects/index.js')));
    }

    public function test_the_chrome_follows_the_configured_icon_set(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $url = route('projects.work-items', $project);

        // Legacy: the icons the app shipped with, drawn inline.
        config()->set('icons.set', 'legacy');
        $legacy = $this->actingAs($owner)->get($url)->assertOk()->getContent();
        $this->assertStringContainsString('<svg', $legacy);
        $this->assertStringNotContainsString('fa-regular', $legacy);

        // Font Awesome: the same chrome, one config value later, with no markup edited.
        // This is the property that makes the switch a revert rather than a rewrite.
        config()->set('icons.set', 'fontawesome');
        config()->set('icons.fa_family', 'sharp');
        $fa = $this->actingAs($owner)->get($url)->assertOk()->getContent();
        $this->assertStringContainsString('fa-sharp fa-regular fa-gear', $fa);
        $this->assertStringContainsString('fa-sharp fa-regular fa-bars', $fa);
        $this->assertStringContainsString('sharp-regular.min.css', $fa);
    }
}
