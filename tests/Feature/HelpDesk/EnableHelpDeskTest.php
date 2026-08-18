<?php

namespace Tests\Feature\HelpDesk;

use App\Models\HelpDeskMember;
use App\Models\WorkspaceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Enabling Help Desk (docs/features/help-desk.md — Phase 1, slice 1: FR-1.1, FR-1.2).
 *
 * The four things the phase's acceptance criteria say about the switch itself: it can be chosen
 * at creation and afterwards, it activates navigation, it grants nobody access on its own, and
 * turning it off hides the app without destroying anything.
 */
class EnableHelpDeskTest extends HelpDeskTestCase
{
    use RefreshDatabase;

    private function enabled(\App\Models\Workspace $workspace): bool
    {
        return (bool) $workspace->run(fn () => WorkspaceSettings::query()->value('help_desk_enabled'));
    }

    // ---- choosing the app -------------------------------------------------------------------

    public function test_a_workspace_can_be_created_with_help_desk(): void
    {
        [, $workspace] = $this->owner();

        $this->assertTrue($this->enabled($workspace));
    }

    public function test_a_workspace_created_without_it_does_not_have_it(): void
    {
        [, $workspace] = $this->owner('acme-inc', ['projects']);

        $this->assertFalse($this->enabled($workspace));
    }

    public function test_the_create_screen_offers_help_desk_as_a_real_choice(): void
    {
        // Somebody with no workspace yet — the create screen redirects anybody who has one.
        $newcomer = \App\Models\User::factory()->create(['current_workspace_id' => null]);

        $this->actingAs($newcomer)
            ->get(route('onboarding.workspace'))
            ->assertOk()
            // A checkbox, not the Coming Soon placeholder it rendered as before slice 1.
            ->assertSee('name="apps[]" value="helpdesk"', false);
    }

    public function test_it_can_be_switched_on_and_off_from_settings(): void
    {
        [$owner, $workspace] = $this->owner('acme-inc', ['projects']);

        $this->actingAs($owner)->patchJson('/settings/general', [
            'name' => 'Acme Inc', 'slug' => 'acme-inc', 'company_size' => '2-10',
            'timezone' => 'UTC', 'apps' => ['helpdesk'],
        ])->assertOk();

        $this->assertTrue($this->enabled($workspace));

        $this->actingAs($owner->fresh())->patchJson('/settings/general', [
            'name' => 'Acme Inc', 'slug' => 'acme-inc', 'company_size' => '2-10',
            'timezone' => 'UTC', 'apps' => [],
        ])->assertOk();

        $this->assertFalse($this->enabled($workspace));
    }

    public function test_a_plain_member_cannot_switch_it_on(): void
    {
        [, $workspace] = $this->owner('acme-inc', ['projects']);
        $member = $this->member($workspace, 'member', 'member@example.com');

        // §13: server-side authorization. Not offering the control is not the same as refusing.
        $this->actingAs($member)->patchJson('/settings/general', [
            'name' => 'Acme Inc', 'slug' => 'acme-inc', 'company_size' => '2-10',
            'timezone' => 'UTC', 'apps' => ['helpdesk'],
        ])->assertForbidden();

        $this->assertFalse($this->enabled($workspace));
    }

    // ---- navigation (FR-1.2) ----------------------------------------------------------------

    public function test_the_rail_shows_help_desk_only_once_it_is_enabled(): void
    {
        [$owner, $workspace] = $this->owner('acme-inc', ['projects']);

        $this->actingAs($owner)->get(route('projects.index'))
            ->assertOk()->assertDontSee(route('help-desk.index'), false);

        $this->enable($workspace);

        $this->actingAs($owner->fresh())->get(route('projects.index'))
            ->assertOk()->assertSee(route('help-desk.index'), false);
    }

    public function test_the_area_is_not_reachable_when_the_app_is_off(): void
    {
        [$owner] = $this->owner('acme-inc', ['projects']);

        // Hidden from the rail is not the same as unreachable — the URL is guessable.
        $this->actingAs($owner)->get('/help-desk')->assertNotFound();
    }

    public function test_the_area_opens_when_enabled(): void
    {
        [$owner] = $this->owner();

        $this->actingAs($owner)->get('/help-desk')->assertOk()->assertSee('Help Desk');
    }

    // ---- what enabling does NOT do ----------------------------------------------------------

    public function test_enabling_grants_no_workspace_member_help_desk_access(): void
    {
        [, $workspace] = $this->owner();
        $member = $this->member($workspace, 'member', 'member@example.com');

        // The first acceptance criterion, and the whole point of the separate membership model.
        $this->actingAs($member)->get('/help-desk')->assertNotFound();
        $this->actingAs($member->fresh())->get(route('projects.index'))
            ->assertOk()->assertDontSee(route('help-desk.index'), false);

        $this->assertNull($this->membershipOf($workspace, $member));
    }

    // ---- switching it off (acceptance criterion 4) -------------------------------------------

    public function test_disabling_hides_the_app_but_keeps_every_help_desk_record(): void
    {
        [$owner, $workspace] = $this->owner();
        $agent = $this->member($workspace, 'member', 'agent@example.com');
        $this->addToHelpDesk($workspace, $owner, $agent, HelpDeskMember::ROLE_AGENT);

        $this->enable($workspace, false);

        // Gone from the rail, and refused at the URL…
        $this->actingAs($owner->fresh())->get('/help-desk')->assertNotFound();
        $this->actingAs($agent->fresh())->get('/help-desk')->assertNotFound();

        // …while the membership, its role and the Help Desk itself are untouched.
        $membership = $this->membershipOf($workspace, $agent);
        $this->assertNotNull($membership);
        $this->assertSame(HelpDeskMember::ROLE_AGENT, $membership->role);

        $this->enable($workspace);

        $this->actingAs($agent->fresh())->get('/help-desk')->assertOk();
    }

    // ---- tenancy (§12: multi-tenant isolation) -----------------------------------------------

    public function test_enabling_it_for_one_workspace_leaves_another_alone(): void
    {
        [, $enabled] = $this->owner('acme-inc');
        [$otherOwner, $untouched] = $this->owner('globex', ['projects']);

        $this->assertTrue($this->enabled($enabled));
        $this->assertFalse($this->enabled($untouched));

        $this->actingAs($otherOwner)->get('/help-desk')->assertNotFound();
    }
}
