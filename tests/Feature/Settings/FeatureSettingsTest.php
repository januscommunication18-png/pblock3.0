<?php

namespace Tests\Feature\Settings;

use App\Models\CustomerProperty;
use App\Models\InitiativeLabel;
use App\Models\ReleaseTag;
use App\Models\WikiLabel;
use App\Models\WorkspaceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;

/** Wiki, Releases, Initiatives, Customers, Teamspaces (spec §7-§11). */
class FeatureSettingsTest extends SettingsTestCase
{
    use RefreshDatabase;

    public function test_wiki_is_off_by_default_then_toggles_with_labels(): void
    {
        [$owner, $workspace] = $this->owner();

        // Visiting the section provisions settings — Wiki is off by default.
        $this->actingAs($owner)->get(route('settings.wiki'))->assertOk();
        $this->assertFalse($workspace->run(fn () => WorkspaceSettings::first()->wiki_enabled));

        // Enable, then labels can be created.
        $this->actingAs($owner)->postJson(route('settings.wiki.toggle'), ['enabled' => true])
            ->assertOk()->assertJsonPath('enabled', true);
        $this->actingAs($owner)->postJson(route('settings.wiki.labels.store'), ['name' => 'Guide', 'color' => '#22C55E'])->assertOk();

        $this->assertTrue($workspace->run(fn () => WorkspaceSettings::first()->wiki_enabled));
        $this->assertTrue($workspace->run(fn () => WikiLabel::where('name', 'Guide')->exists()));
    }

    public function test_releases_toggle_tag_and_label(): void
    {
        [$owner, $workspace] = $this->owner();

        $this->actingAs($owner)->postJson(route('settings.releases.tags.store'), ['name' => 'v1.0.0'])
            ->assertOk()->assertJsonPath('tags.0.name', 'v1.0.0');
        $this->actingAs($owner)->postJson(route('settings.releases.labels.store'), ['name' => 'Hotfix', 'color' => '#DC2626'])->assertOk();

        $this->assertTrue($workspace->run(fn () => ReleaseTag::where('name', 'v1.0.0')->exists()));
    }

    public function test_initiative_labels_are_locked_until_enabled(): void
    {
        [$owner, $workspace] = $this->owner();

        // Default off → label writes rejected (spec §9).
        $this->actingAs($owner)->postJson(route('settings.initiatives.labels.store'), ['name' => 'Q3', 'color' => '#2563EB'])
            ->assertStatus(422);
        $this->assertSame(0, $workspace->run(fn () => InitiativeLabel::count()));

        // Enable, then it succeeds.
        $this->actingAs($owner)->postJson(route('settings.initiatives.toggle'), ['enabled' => true])->assertOk();
        $this->actingAs($owner)->postJson(route('settings.initiatives.labels.store'), ['name' => 'Q3', 'color' => '#2563EB'])->assertOk();
        $this->assertSame(1, $workspace->run(fn () => InitiativeLabel::count()));
    }

    public function test_customers_toggle_and_property_crud_with_dropdown_options(): void
    {
        [$owner, $workspace] = $this->owner();

        $this->actingAs($owner)->postJson(route('settings.customers.properties.store'), [
            'title' => 'Tier', 'type' => 'Dropdown', 'mandatory' => true, 'active' => true,
            'options' => ['Gold', 'Silver', 'Bronze'],
        ])->assertOk();

        $prop = $workspace->run(fn () => CustomerProperty::first());
        $this->assertSame('Tier', $prop->title);
        $this->assertTrue($prop->mandatory);
        $this->assertSame(['Gold', 'Silver', 'Bronze'], $prop->options);
    }

    public function test_dropdown_property_requires_options(): void
    {
        [$owner] = $this->owner();

        $this->actingAs($owner)->postJson(route('settings.customers.properties.store'), [
            'title' => 'Tier', 'type' => 'Dropdown',
        ])->assertStatus(422)->assertJsonValidationErrors('options');
    }

    public function test_each_workspace_has_its_own_settings(): void
    {
        [$ownerA, $wsA] = $this->owner('ws-a');
        [$ownerB, $wsB] = $this->owner('ws-b');

        // Disable Customers in workspace A only.
        $this->actingAs($ownerA)->postJson(route('settings.customers.toggle'), ['enabled' => false])->assertOk();
        // Enable Initiatives in workspace B only.
        $this->actingAs($ownerB)->postJson(route('settings.initiatives.toggle'), ['enabled' => true])->assertOk();

        // Workspace A: Customers off, Initiatives still default-off.
        $a = $wsA->run(fn () => WorkspaceSettings::first());
        $this->assertFalse($a->customers_enabled);
        $this->assertFalse($a->initiatives_enabled);

        // Workspace B: untouched by A — Customers still on, Initiatives on.
        $b = $wsB->run(fn () => WorkspaceSettings::first());
        $this->assertTrue($b->customers_enabled);
        $this->assertTrue($b->initiatives_enabled);
    }

    public function test_teamspaces_enable_is_one_way(): void
    {
        [$owner, $workspace] = $this->owner();

        $this->actingAs($owner)->postJson(route('settings.teamspaces.toggle'), ['enabled' => true])
            ->assertOk()->assertJsonPath('locked', true);

        // A later disable request is rejected (spec §10).
        $this->actingAs($owner)->postJson(route('settings.teamspaces.toggle'), ['enabled' => false])
            ->assertStatus(422);

        $this->assertTrue($workspace->run(fn () => WorkspaceSettings::first()->teamspaces_enabled));
    }
}
