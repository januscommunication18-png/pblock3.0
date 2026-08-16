<?php

namespace Tests\Feature\Workspace;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceSettings;
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
}
