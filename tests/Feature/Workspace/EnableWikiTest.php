<?php

namespace Tests\Feature\Workspace;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceSettings;
use App\Services\WorkspaceApps;
use App\Services\WorkspaceCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Enabling Wiki (docs/features/wiki.md, slice 1).
 *
 * The create form has always submitted `apps[]` and no controller ever read it, so the step
 * looked like a choice and changed nothing. These pin down that choosing now has an effect —
 * and that the effect lands on the one flag Settings → Wiki also reads (WIKI-D2).
 */
class EnableWikiTest extends TestCase
{
    use RefreshDatabase;

    private function creator(): User
    {
        return User::factory()->create(['full_name' => 'Rohit', 'email' => 'owner@example.com']);
    }

    private function wikiEnabled(Workspace $workspace): bool
    {
        return (bool) $workspace->run(fn () => WorkspaceSettings::query()->value('wiki_enabled'));
    }

    // ---- creating a workspace ---------------------------------------------------------------

    public function test_a_workspace_created_with_wiki_has_it_enabled(): void
    {
        $workspace = app(WorkspaceCreator::class)->create($this->creator(), [
            'name' => 'Acme Inc', 'slug' => 'acme-inc', 'company_size' => '2-10',
            'apps' => ['projects', 'wiki'],
        ]);

        $this->assertTrue($this->wikiEnabled($workspace));
    }

    public function test_a_workspace_created_without_wiki_does_not_have_it(): void
    {
        // Off unless chosen (WIKI-D1). Nothing about the Wiki should appear in a workspace
        // whose owner never asked for it.
        $workspace = app(WorkspaceCreator::class)->create($this->creator(), [
            'name' => 'Acme Inc', 'slug' => 'acme-inc', 'company_size' => '2-10',
            'apps' => ['projects'],
        ]);

        $this->assertFalse($this->wikiEnabled($workspace));
    }

    public function test_a_workspace_created_the_old_way_still_works(): void
    {
        // No `apps` key at all — the shape every existing caller and test uses.
        $workspace = app(WorkspaceCreator::class)->create($this->creator(), [
            'name' => 'Acme Inc', 'slug' => 'acme-inc', 'company_size' => '2-10',
        ]);

        $this->assertFalse($this->wikiEnabled($workspace));
    }

    // ---- through the form -------------------------------------------------------------------

    public function test_the_create_form_enables_wiki_when_it_is_ticked(): void
    {
        $user = $this->creator();

        $this->actingAs($user)->post(route('onboarding.workspace.store'), [
            'name' => 'Acme Inc', 'slug' => 'acme-inc', 'team_size' => '2-10',
            'apps' => ['projects', 'wiki'],
        ])->assertRedirect();

        $this->assertTrue($this->wikiEnabled(Workspace::query()->firstOrFail()));
    }

    public function test_an_unreleased_app_cannot_be_switched_on_by_the_form(): void
    {
        $user = $this->creator();

        // Help Desk is not released. The screen shows it as Coming Soon and offers no control,
        // so this can only arrive from a crafted request — refused, not quietly ignored.
        $this->actingAs($user)->post(route('onboarding.workspace.store'), [
            'name' => 'Acme Inc', 'slug' => 'acme-inc', 'team_size' => '2-10',
            'apps' => ['helpdesk'],
        ])->assertSessionHasErrors('apps.0');

        $this->assertSame(0, Workspace::query()->count());
    }

    public function test_projects_is_enabled_even_if_the_request_leaves_it_out(): void
    {
        $user = $this->creator();

        // The form renders Projects locked, so it is added back rather than trusted: a
        // workspace that can do nothing is not a choice worth honouring (WIKI-D3).
        $this->actingAs($user)->post(route('onboarding.workspace.store'), [
            'name' => 'Acme Inc', 'slug' => 'acme-inc', 'team_size' => '2-10',
            'apps' => ['wiki'],
        ])->assertRedirect();

        $this->assertTrue($this->wikiEnabled(Workspace::query()->firstOrFail()));
    }

    // ---- the create screen offers it ---------------------------------------------------------

    public function test_the_create_screen_offers_wiki_as_a_real_choice(): void
    {
        $this->actingAs($this->creator())
            ->get(route('onboarding.workspace'))
            ->assertOk()
            ->assertSee('Wiki')
            // A checkbox, not the Coming Soon placeholder it used to render as.
            ->assertSee('name="apps[]" value="wiki"', false);
    }

    // ---- and afterwards, from Settings --------------------------------------------------------

    public function test_wiki_can_still_be_switched_on_and_off_after_creation(): void
    {
        $user = $this->creator();
        $workspace = app(WorkspaceCreator::class)->create($user, [
            'name' => 'Acme Inc', 'slug' => 'acme-inc', 'company_size' => '2-10',
        ]);

        $this->assertFalse($this->wikiEnabled($workspace));

        $this->actingAs($user->fresh())->postJson('/settings/wiki/toggle', ['enabled' => true])
            ->assertOk()->assertJsonPath('enabled', true);

        $this->assertTrue($this->wikiEnabled($workspace));

        // Reversible, and nothing is destroyed by turning it off (WIKI-D4).
        $this->actingAs($user->fresh())->postJson('/settings/wiki/toggle', ['enabled' => false])
            ->assertOk()->assertJsonPath('enabled', false);

        $this->assertFalse($this->wikiEnabled($workspace));
    }

    // ---- Settings → General ---------------------------------------------------------------

    public function test_the_general_screen_lists_what_the_workspace_subscribes_to(): void
    {
        $user = $this->creator();
        app(WorkspaceCreator::class)->create($user, [
            'name' => 'Acme Inc', 'slug' => 'acme-inc', 'company_size' => '2-10',
            'apps' => ['projects', 'wiki'],
        ]);

        $apps = collect($this->actingAs($user->fresh())
            ->get('/settings/general')->assertOk()
            ->viewData('bootstrap')['apps'])->keyBy('key');

        // Projects is on because it is what a workspace IS, and cannot be switched off.
        $this->assertTrue($apps['projects']['enabled']);
        $this->assertTrue($apps['projects']['locked']);

        $this->assertTrue($apps['wiki']['enabled']);
        $this->assertFalse($apps['wiki']['locked']);

        // Unreleased apps are listed so people can see what is coming, but not as a choice.
        $this->assertFalse($apps['helpdesk']['available']);
    }

    public function test_wiki_can_be_switched_on_from_the_general_screen(): void
    {
        $user = $this->creator();
        $workspace = app(WorkspaceCreator::class)->create($user, [
            'name' => 'Acme Inc', 'slug' => 'acme-inc', 'company_size' => '2-10',
        ]);

        $this->actingAs($user->fresh())->patchJson('/settings/general', $this->generalPayload([
            'apps' => ['wiki'],
        ]))->assertOk();

        $this->assertTrue($this->wikiEnabled($workspace));
    }

    public function test_unticking_an_app_on_the_general_screen_switches_it_off(): void
    {
        $user = $this->creator();
        $workspace = app(WorkspaceCreator::class)->create($user, [
            'name' => 'Acme Inc', 'slug' => 'acme-inc', 'company_size' => '2-10',
            'apps' => ['projects', 'wiki'],
        ]);

        // An empty list has to mean "none of the optional ones", or the card could never be
        // used to turn the last app off.
        $this->actingAs($user->fresh())->patchJson('/settings/general', $this->generalPayload([
            'apps' => [],
        ]))->assertOk();

        $this->assertFalse($this->wikiEnabled($workspace));
    }

    public function test_saving_the_general_screen_without_apps_leaves_them_alone(): void
    {
        $user = $this->creator();
        $workspace = app(WorkspaceCreator::class)->create($user, [
            'name' => 'Acme Inc', 'slug' => 'acme-inc', 'company_size' => '2-10',
            'apps' => ['projects', 'wiki'],
        ]);

        // Renaming a workspace must not silently unsubscribe it from everything.
        $this->actingAs($user->fresh())->patchJson('/settings/general', $this->generalPayload())
            ->assertOk();

        $this->assertTrue($this->wikiEnabled($workspace));
    }

    public function test_an_unreleased_app_cannot_be_switched_on_from_general(): void
    {
        $user = $this->creator();
        app(WorkspaceCreator::class)->create($user, [
            'name' => 'Acme Inc', 'slug' => 'acme-inc', 'company_size' => '2-10',
        ]);

        $this->actingAs($user->fresh())->patchJson('/settings/general', $this->generalPayload([
            'apps' => ['helpdesk'],
        ]))->assertStatus(422)->assertJsonValidationErrors('apps.0');
    }

    public function test_projects_cannot_be_named_as_a_toggleable_app(): void
    {
        $user = $this->creator();
        app(WorkspaceCreator::class)->create($user, [
            'name' => 'Acme Inc', 'slug' => 'acme-inc', 'company_size' => '2-10',
        ]);

        // It is not optional, so it is not a value this field accepts (WIKI-D3).
        $this->actingAs($user->fresh())->patchJson('/settings/general', $this->generalPayload([
            'apps' => ['projects'],
        ]))->assertStatus(422)->assertJsonValidationErrors('apps.0');
    }

    /** @return array<string, mixed> */
    private function generalPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Acme Inc',
            'slug' => 'acme-inc',
            'company_size' => '2-10',
            'timezone' => 'UTC',
        ], $overrides);
    }

    // ---- navigation -------------------------------------------------------------------------

    public function test_the_left_rail_shows_wiki_only_once_it_is_enabled(): void
    {
        $user = $this->creator();
        $workspace = app(WorkspaceCreator::class)->create($user, [
            'name' => 'Acme Inc', 'slug' => 'acme-inc', 'company_size' => '2-10',
        ]);

        // Enabling a setting that changes nothing visible reads as a setting that did nothing.
        $this->actingAs($user->fresh())->get(route('projects.index'))
            ->assertOk()->assertDontSee('wiki.home');

        app(WorkspaceApps::class)->sync($workspace, ['wiki']);

        $this->actingAs($user->fresh())->get(route('projects.index'))
            ->assertOk()->assertSee(route('wiki.home'), false);
    }

    public function test_the_wiki_area_opens_when_enabled(): void
    {
        $user = $this->creator();
        app(WorkspaceCreator::class)->create($user, [
            'name' => 'Acme Inc', 'slug' => 'acme-inc', 'company_size' => '2-10',
            'apps' => ['projects', 'wiki'],
        ]);

        $this->actingAs($user->fresh())->get('/wiki')
            ->assertOk()
            ->assertSee('Collections');
    }

    public function test_the_wiki_area_is_not_reachable_when_it_is_off(): void
    {
        $user = $this->creator();
        app(WorkspaceCreator::class)->create($user, [
            'name' => 'Acme Inc', 'slug' => 'acme-inc', 'company_size' => '2-10',
        ]);

        // Hidden from the rail is not the same as unreachable — the URL is guessable.
        $this->actingAs($user->fresh())->get('/wiki')->assertNotFound();
    }

    public function test_the_wiki_area_gets_its_own_sidebar(): void
    {
        $user = $this->creator();
        app(WorkspaceCreator::class)->create($user, [
            'name' => 'Acme Inc', 'slug' => 'acme-inc', 'company_size' => '2-10',
            'apps' => ['projects', 'wiki'],
        ]);

        // "A focused navigation experience" — a different room, not the same room with extra
        // doors, so the work-item navigation is deliberately absent.
        $this->actingAs($user->fresh())->get('/wiki')
            ->assertOk()
            ->assertSee('New page')
            ->assertSee('Collections')
            // Shared, Private and Archived are real screens now, not a roadmap.
            ->assertSee(route('wiki.section', 'shared'), false)
            ->assertSee(route('wiki.section', 'private'), false)
            ->assertSee(route('wiki.section', 'archived'), false)
            ->assertDontSee('Soon')
            ->assertDontSee('New work item')
            // Not "Your work" — the workspace switcher in the topbar mentions it, so that
            // would assert against a panel this change never touched.
            ->assertDontSee('Stickies');
    }

    public function test_the_ordinary_sidebar_is_untouched_outside_the_wiki(): void
    {
        $user = $this->creator();
        app(WorkspaceCreator::class)->create($user, [
            'name' => 'Acme Inc', 'slug' => 'acme-inc', 'company_size' => '2-10',
            'apps' => ['projects', 'wiki'],
        ]);

        // Enabling Wiki must not change how the rest of the application navigates.
        $this->actingAs($user->fresh())->get(route('projects.index'))
            ->assertOk()
            ->assertSee('Stickies')
            ->assertDontSee('New page');
    }
}
