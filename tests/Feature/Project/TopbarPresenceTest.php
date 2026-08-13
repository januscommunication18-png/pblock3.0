<?php

namespace Tests\Feature\Project;

use App\Services\WorkspaceCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

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

    /**
     * The account menu is the same menu on every screen that has one.
     *
     * It used to be written out three times — app-topbar, app/projects and app/welcome, the
     * last two rendering their own headers — and the three had drifted to different icons and
     * different items. Updating app-topbar therefore left the projects screen, which is where
     * you land, still showing the old menu. It is one partial now, and this is what says so.
     */
    public function test_the_account_menu_is_the_same_on_every_screen_that_has_one(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['name' => 'Testing', 'identifier' => 'TESTI']);

        $screens = [
            route('projects.index'),                          // app/projects — its own header
            route('welcome'),                                 // app/welcome  — its own header
            route('projects.work-items', $project->id),       // partials/app-topbar
        ];

        foreach ($screens as $url) {
            $html = $this->actingAs($owner->fresh())->get($url)->assertOk()->getContent();

            foreach (['data-account-open="profile"', 'data-account-open="preference"', 'Sign Off'] as $needle) {
                $this->assertStringContainsString($needle, $html, "Account menu item missing on {$url}: {$needle}");
            }

            // The dialog ships with the menu — an item that opens nothing is worse than no item.
            $this->assertStringContainsString('id="account-modal"', $html, "No account modal on {$url}");

            // The entries it replaced are gone from THE MENU. Scoped to the menu's own markup,
            // because Workspace settings is still reachable from the sidebar and should be —
            // asserting against the whole page would be asserting the wrong thing.
            $menu = substr($html, strpos($html, 'id="user-menu"'));
            $menu = substr($menu, 0, strpos($menu, 'id="account-modal"'));

            foreach (['Workspace settings', 'Create workspace', 'New project', 'Sign out'] as $gone) {
                $this->assertStringNotContainsString($gone, $menu, "Stale account menu item on {$url}: {$gone}");
            }
        }
    }

    /**
     * The menu's header card shows the person, not a placeholder of them.
     *
     * It used to be a hardcoded grey block and an initial, so a user who had uploaded a cover
     * and a photo saw neither anywhere except inside the dialog they set them in. Rendered
     * server-side, so it is already right on the first paint rather than after JavaScript runs.
     */
    public function test_the_account_menu_shows_the_stored_cover_and_photo(): void
    {
        Storage::fake('local');

        [$owner] = $this->owner();
        $this->actingAs($owner)->post(route('account.profile.image.store'), [
            'kind' => 'avatar',
            'image' => UploadedFile::fake()->image('me.jpg'),
        ])->assertOk();

        $gradient = config('projects.cover_gradients')[3];
        $this->actingAs($owner)->patchJson(route('account.profile.update'), ['cover_gradient' => $gradient])->assertOk();

        $html = $this->actingAs($owner->fresh())->get(route('projects.index'))->assertOk()->getContent();

        $avatar = route('account.image', ['user' => $owner->id, 'kind' => 'avatar']);

        // The photo, on the header chip and on the menu's own card.
        $this->assertStringContainsString('id="user-menu-avatar"', $html);
        $this->assertGreaterThanOrEqual(2, substr_count($html, $avatar),
            'the photo should appear on both the header button and the menu card');

        // …and the banner behind it is the chosen gradient, not the old fixed grey.
        $this->assertStringContainsString($gradient, $html);
        $this->assertStringNotContainsString('background-color:#9ca3af', $html);
    }

    public function test_workspace_settings_uses_minimal_settings_header(): void
    {
        // Settings runs in its own "settings mode" shell — NOT the full app topbar.
        [$owner] = $this->owner();
        $html = $this->actingAs($owner)->get(route('settings.general'))->assertOk()->getContent();
        $this->assertStringNotContainsString('id="user-btn"', $html);
        $this->assertStringContainsString('Close settings', $html);
    }

    /**
     * Every workspace settings screen offers a way out on the left as well as on the right.
     *
     * Settings is a full-screen detour with no app sidebar and no topbar, so Close alone was
     * the only exit — while the PROJECT settings shell beside it has had both since it was
     * built. Asserted across every section because they share one layout: if that ever stops
     * being true, this is what says so.
     */
    public function test_every_workspace_settings_screen_has_a_back_control(): void
    {
        [$owner] = $this->owner();

        foreach (['general', 'members', 'projects'] as $section) {
            $html = $this->actingAs($owner)->get(route("settings.{$section}"))->assertOk()->getContent();

            $this->assertStringContainsString('Back to projects', $html, "No back control on settings.{$section}");
            // Both exits, not one replacing the other.
            $this->assertStringContainsString('Close settings', $html, "No close control on settings.{$section}");
        }
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
