<?php

namespace Tests\Feature\HelpDesk;

use App\Models\HelpDesk;
use App\Models\HelpDeskActivity;
use App\Models\HelpDeskInbox;
use App\Models\HelpDeskMember;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The first-time setup wizard (docs/features/help-desk.md — Phase 1, slice 4: FR-1.3).
 *
 * §4's sequence is "Enable > Create first inbox > Invite team > Assign roles > Configure basic
 * settings". Enabling is a workspace setting, so what these pin down is that an administrator
 * who has never set the Help Desk up lands in the wizard, that each step commits as it is
 * taken, and that finishing is a one-way, idempotent door.
 */
class SetupWizardTest extends HelpDeskTestCase
{
    use RefreshDatabase;

    public function test_an_administrator_lands_in_the_wizard_until_setup_is_finished(): void
    {
        [$owner, $workspace] = $this->owner();

        $this->actingAs($owner)->get('/help-desk')->assertRedirect(route('help-desk.setup'));
        // The wizard's copy lives in the mounted component, so the shell is what a server-side
        // assertion can see — its root, and the bootstrap the component is handed.
        $this->actingAs($owner->fresh())->get(route('help-desk.setup'))
            ->assertOk()
            ->assertSee('help-desk-setup-root', false)
            ->assertViewHas('bootstrap');

        $this->actingAs($owner->fresh())->postJson(route('help-desk.setup.complete'))->assertOk();

        // Finished is finished — the wizard is not a settings screen.
        $this->actingAs($owner->fresh())->get('/help-desk')->assertOk();
        $this->actingAs($owner->fresh())->get(route('help-desk.setup'))->assertRedirect(route('help-desk.index'));

        $this->assertNotNull($workspace->run(fn () => HelpDesk::query()->firstOrFail())->setup_completed_at);
    }

    public function test_a_member_is_never_sent_into_the_wizard(): void
    {
        [$owner, $workspace] = $this->owner();
        $agent = $this->member($workspace, 'member', 'agent@example.com');
        $this->addToHelpDesk($workspace, $owner, $agent, HelpDeskMember::ROLE_AGENT, setUp: false);

        // They have nothing to set up, and being redirected into somebody else's configuration
        // screen is how a wizard becomes a wall.
        $this->actingAs($agent->fresh())->get('/help-desk')->assertOk();
        $this->actingAs($agent->fresh())->get(route('help-desk.setup'))->assertForbidden();
    }

    public function test_the_name_step_saves(): void
    {
        [$owner, $workspace] = $this->owner();

        $this->actingAs($owner)->patchJson(route('help-desk.setup.update'), ['name' => 'Acme Support'])
            ->assertOk()->assertJsonPath('name', 'Acme Support');

        $this->assertSame('Acme Support', $workspace->run(fn () => HelpDesk::query()->firstOrFail())->name);
    }

    public function test_the_name_is_required(): void
    {
        [$owner] = $this->owner();

        $this->actingAs($owner)->patchJson(route('help-desk.setup.update'), ['name' => '  '])
            ->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_the_wizard_steps_write_through_the_real_endpoints(): void
    {
        [$owner, $workspace] = $this->owner();
        $teammate = $this->member($workspace, 'member', 'teammate@example.com');

        // The wizard has no save paths of its own: an inbox created in step 2 and a member
        // added in step 3 are the same requests the settings screens make.
        $this->actingAs($owner->fresh())->postJson(route('help-desk.inboxes.store'), ['name' => 'Billing'])->assertOk();
        $this->actingAs($owner->fresh())->postJson(route('help-desk.members.store'), [
            'user_id' => $teammate->id, 'role' => HelpDeskMember::ROLE_AGENT,
        ])->assertOk();

        $this->assertSame(2, $workspace->run(fn () => HelpDeskInbox::query()->count()));
        $this->assertNotNull($this->membershipOf($workspace, $teammate));

        // …and none of it depended on finishing, so a wizard abandoned halfway loses nothing.
        $this->assertNull($workspace->run(fn () => HelpDesk::query()->firstOrFail())->setup_completed_at);
    }

    public function test_finishing_twice_records_one_event(): void
    {
        [$owner, $workspace] = $this->owner();

        $this->actingAs($owner)->postJson(route('help-desk.setup.complete'))->assertOk();
        $completedAt = $workspace->run(fn () => HelpDesk::query()->firstOrFail())->setup_completed_at;

        $this->actingAs($owner->fresh())->postJson(route('help-desk.setup.complete'))->assertOk();

        $this->assertEquals($completedAt, $workspace->run(fn () => HelpDesk::query()->firstOrFail())->setup_completed_at);
        $this->assertSame(1, $workspace->run(fn () => HelpDeskActivity::query()
            ->where('event', HelpDeskActivity::EVENT_SETUP_COMPLETED)->count()));
    }

    public function test_an_agent_cannot_finish_setup(): void
    {
        [$owner, $workspace] = $this->owner();
        $agent = $this->member($workspace, 'member', 'agent@example.com');
        $this->addToHelpDesk($workspace, $owner, $agent, HelpDeskMember::ROLE_AGENT, setUp: false);

        $this->actingAs($agent->fresh())->postJson(route('help-desk.setup.complete'))->assertForbidden();
        $this->actingAs($agent->fresh())->patchJson(route('help-desk.setup.update'), ['name' => 'Mine'])->assertForbidden();
    }
}
