<?php

namespace Tests\Feature\HelpDesk;

use App\Models\HelpDeskEmailAddress;
use App\Models\HelpDeskInbox;
use App\Models\HelpDeskMember;
use App\Models\HelpDeskSpace;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Services\HelpDesk\InboundEmailIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

/**
 * Continue to Setup Inbox — the four-step flow (Setup Inbox requirements).
 *
 * Its acceptance criteria, and the two properties the whole thing rests on:
 *
 *   every step COMMITS as it is taken, so abandoning the flow leaves a working inbox rather
 *   than a lost draft — which is also what makes "resume from the first incomplete step" true;
 *
 *   the generated address is `inbox-{unique-id}@…` and carries nothing derived from the inbox,
 *   so renaming it and moving it between spaces both leave it alone.
 */
class SpaceSetupTest extends HelpDeskTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Step 4 invites a stranger, which sends mail.
        Mail::fake();
    }

    /** A space with nothing set up yet — what "Continue to Setup Inbox" is offered for. */
    private function freshSpace(Workspace $workspace, User $owner, string $name = 'Partner Support'): HelpDeskSpace
    {
        $this->helpDesk($workspace, $owner);

        $this->actingAs($owner->fresh())
            ->postJson(route('help-desk.spaces.store'), ['name' => $name])
            ->assertOk();

        return $workspace->run(fn () => HelpDeskSpace::query()->where('name', $name)->firstOrFail());
    }

    private function nameInbox(User $actor, HelpDeskSpace $space, string $name = 'Customer Support'): void
    {
        $this->actingAs($actor->fresh())
            ->postJson(route('help-desk.spaces.setup.name', $space), ['name' => $name])
            ->assertOk();
    }

    private function addAddress(User $actor, HelpDeskSpace $space, string $address, ?string $label = null): void
    {
        $this->actingAs($actor->fresh())
            ->postJson(route('help-desk.spaces.setup.addresses', $space), ['address' => $address, 'label' => $label])
            ->assertOk();
    }

    // ---- the list offers the flow ---------------------------------------------------------------

    public function test_an_unfinished_space_offers_continue_to_setup_inbox(): void
    {
        [$owner, $workspace] = $this->owner();
        $space = $this->freshSpace($workspace, $owner);

        $rows = collect($this->actingAs($owner->fresh())->get(route('help-desk.spaces'))
            ->assertOk()->viewData('bootstrap')['spaces'])->keyBy('name');

        $this->assertFalse($rows['Partner Support']['setup_complete']);
        $this->assertSame('Setup required', $rows['Partner Support']['inbox_status']);
        $this->assertSame(route('help-desk.spaces.setup', $space), $rows['Partner Support']['setup_url']);

        /*
         * The space provisioning made is unfinished too, and deliberately: it has an inbox with
         * a generated address, but nothing forwards into it yet, so it receives nothing. Its
         * flow opens at step 2 rather than step 1 — there is no name left to ask for.
         */
        $default = $rows[config('help-desk.default_space')];
        $this->assertFalse($default['setup_complete']);
        $this->assertSame(HelpDeskSpace::STEP_ADDRESSES, $default['setup_step']);
    }

    public function test_an_agent_cannot_open_or_drive_the_flow(): void
    {
        [$owner, $workspace] = $this->owner();
        $space = $this->freshSpace($workspace, $owner);
        $agent = $this->member($workspace, 'member', 'agent@example.com');
        $this->addToHelpDesk($workspace, $owner, $agent, HelpDeskMember::ROLE_AGENT);

        $this->actingAs($agent->fresh())->get(route('help-desk.spaces.setup', $space))->assertForbidden();
        $this->actingAs($agent->fresh())
            ->postJson(route('help-desk.spaces.setup.name', $space), ['name' => 'Mine'])
            ->assertForbidden();
    }

    public function test_another_workspaces_space_cannot_be_set_up_from_here(): void
    {
        [$ownerA, $workspaceA] = $this->owner('acme-inc');
        [$ownerB, $workspaceB] = $this->owner('globex');
        $this->helpDesk($workspaceA, $ownerA);
        $this->helpDesk($workspaceB, $ownerB);
        $theirs = $workspaceB->run(fn () => HelpDeskSpace::query()->firstOrFail());

        $this->actingAs($ownerA->fresh())->get(route('help-desk.spaces.setup', $theirs))->assertNotFound();
    }

    // ---- step 1 ------------------------------------------------------------------------------------

    public function test_step_one_creates_the_inbox_with_a_generated_address(): void
    {
        [$owner, $workspace] = $this->owner();
        $space = $this->freshSpace($workspace, $owner);

        $this->nameInbox($owner, $space, 'Customer Support');

        $inbox = $workspace->run(fn () => HelpDeskInbox::query()->where('name', 'Customer Support')->firstOrFail());

        $this->assertSame($space->id, $inbox->help_desk_space_id);
        $this->assertNotEmpty($inbox->inbound_id);

        // `inbox-{unique-id}@{domain}`, with the inbox NAME nowhere in it.
        $this->assertSame(
            'inbox-'.$inbox->inbound_id.'@'.config('help-desk.inbound.domain'),
            $inbox->inbound_address,
        );
        $this->assertStringNotContainsString('customer', (string) $inbox->inbound_address);

        // And the step is recorded, which is what makes the flow resumable.
        $this->assertSame(HelpDeskSpace::STEP_ADDRESSES, $workspace->run(fn () => HelpDeskSpace::find($space->id))->setup_step);
    }

    public function test_a_blank_or_duplicate_inbox_name_is_refused(): void
    {
        [$owner, $workspace] = $this->owner();
        $space = $this->freshSpace($workspace, $owner);

        $this->actingAs($owner->fresh())
            ->postJson(route('help-desk.spaces.setup.name', $space), ['name' => '   '])
            ->assertStatus(422)->assertJsonValidationErrors('name');

        // The provisioned inbox is already called this, and names are unique per Help Desk.
        $this->actingAs($owner->fresh())
            ->postJson(route('help-desk.spaces.setup.name', $space), ['name' => config('help-desk.default_inbox')])
            ->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_going_back_to_step_one_renames_rather_than_creating_a_second_inbox(): void
    {
        [$owner, $workspace] = $this->owner();
        $space = $this->freshSpace($workspace, $owner);

        $this->nameInbox($owner, $space, 'Customer Support');
        $inbox = $workspace->run(fn () => HelpDeskInbox::query()->where('name', 'Customer Support')->firstOrFail());
        $address = (string) $inbox->inbound_address;

        $this->nameInbox($owner, $space, 'Customer Experience');

        $fresh = $workspace->run(fn () => HelpDeskInbox::find($inbox->id));

        $this->assertSame('Customer Experience', $fresh->name);
        // The requirement, stated three ways in the flow and true here: renaming does not touch
        // the address, because a forwarding rule in somebody else's mail provider points at it.
        $this->assertSame($address, $fresh->inbound_address);
        $this->assertSame(1, $workspace->run(fn () => HelpDeskInbox::query()->where('help_desk_space_id', $space->id)->count()));
    }

    public function test_moving_the_inbox_to_another_space_leaves_its_address_alone(): void
    {
        [$owner, $workspace] = $this->owner();
        $space = $this->freshSpace($workspace, $owner);
        $this->nameInbox($owner, $space);

        $inbox = $workspace->run(fn () => HelpDeskInbox::query()->where('name', 'Customer Support')->firstOrFail());
        $address = (string) $inbox->inbound_address;

        $other = $this->freshSpace($workspace, $owner, 'Retail Support');

        $this->actingAs($owner->fresh())
            ->postJson(route('help-desk.spaces.assign', $other), ['inbox_ids' => [$inbox->id]])
            ->assertOk();

        $fresh = $workspace->run(fn () => HelpDeskInbox::find($inbox->id));

        $this->assertSame($other->id, $fresh->help_desk_space_id);
        $this->assertSame($address, $fresh->inbound_address);
    }

    // ---- step 2 ------------------------------------------------------------------------------------

    public function test_step_two_adds_edits_and_removes_customer_addresses(): void
    {
        [$owner, $workspace] = $this->owner();
        $space = $this->freshSpace($workspace, $owner);
        $this->nameInbox($owner, $space);

        $this->addAddress($owner, $space, 'support@company.com', 'Customer Support');
        $this->addAddress($owner, $space, 'billing@company.com', 'Billing Support');

        $rows = $this->actingAs($owner->fresh())->get(route('help-desk.spaces.setup', $space))
            ->assertOk()->viewData('bootstrap')['addresses'];

        $this->assertSame(['support@company.com', 'billing@company.com'], array_column($rows, 'address'));
        $this->assertSame(['Customer Support', 'Billing Support'], array_column($rows, 'name'));
        $this->assertSame(HelpDeskEmailAddress::STATUS_SETUP_REQUIRED, $rows[0]['status']);

        $address = $workspace->run(fn () => HelpDeskEmailAddress::query()->where('address', 'billing@company.com')->firstOrFail());

        $this->actingAs($owner->fresh())->patchJson(
            route('help-desk.spaces.setup.addresses.update', ['space' => $space->id, 'emailAddress' => $address->id]),
            ['address' => 'invoices@company.com', 'label' => 'Invoices'],
        )->assertOk();

        $this->assertSame('invoices@company.com', $workspace->run(fn () => HelpDeskEmailAddress::find($address->id))->address);

        $this->actingAs($owner->fresh())->deleteJson(
            route('help-desk.spaces.setup.addresses.destroy', ['space' => $space->id, 'emailAddress' => $address->id]),
        )->assertOk();

        $this->assertSame(1, $workspace->run(fn () => HelpDeskEmailAddress::query()->count()));
    }

    public function test_changing_an_address_resets_what_we_knew_about_the_old_one(): void
    {
        [$owner, $workspace] = $this->owner();
        $space = $this->freshSpace($workspace, $owner);
        $this->nameInbox($owner, $space);
        $this->addAddress($owner, $space, 'support@company.com');

        $address = $workspace->run(fn () => HelpDeskEmailAddress::query()->firstOrFail());
        $workspace->run(fn () => $address->forceFill([
            'status' => HelpDeskEmailAddress::STATUS_CONNECTED,
            'verified_at' => now(),
            'last_email_at' => now(),
        ])->save());

        $this->actingAs($owner->fresh())->patchJson(
            route('help-desk.spaces.setup.addresses.update', ['space' => $space->id, 'emailAddress' => $address->id]),
            ['address' => 'help@company.com'],
        )->assertOk();

        $fresh = $workspace->run(fn () => HelpDeskEmailAddress::find($address->id));

        // What we knew was about a different mailbox. Carrying it across would report that a
        // forwarding rule works when it has never been tried.
        $this->assertSame(HelpDeskEmailAddress::STATUS_SETUP_REQUIRED, $fresh->status);
        $this->assertNull($fresh->verified_at);
        $this->assertNull($fresh->last_email_at);
    }

    public function test_steps_after_the_first_are_refused_until_the_inbox_exists(): void
    {
        [$owner, $workspace] = $this->owner();
        $space = $this->freshSpace($workspace, $owner);

        // The stepper does not offer this; the server refuses it anyway, because a posted
        // request is not a screen.
        $this->actingAs($owner->fresh())
            ->postJson(route('help-desk.spaces.setup.addresses', $space), ['address' => 'support@company.com'])
            ->assertStatus(422);

        $this->actingAs($owner->fresh())
            ->postJson(route('help-desk.spaces.setup.connect', $space))
            ->assertStatus(422);
    }

    // ---- step 3 ------------------------------------------------------------------------------------

    public function test_step_three_verifies_by_watching_and_connects_when_mail_arrives(): void
    {
        [$owner, $workspace] = $this->owner();
        $space = $this->freshSpace($workspace, $owner);
        $this->nameInbox($owner, $space);
        $this->addAddress($owner, $space, 'support@company.com');

        $address = $workspace->run(fn () => HelpDeskEmailAddress::query()->firstOrFail());
        $inbox = $workspace->run(fn () => HelpDeskInbox::find($address->help_desk_inbox_id));

        $this->actingAs($owner->fresh())
            ->postJson(route('help-desk.spaces.setup.verify', $space), ['email_address_id' => $address->id])
            ->assertOk()
            ->assertJsonPath('addresses.0.status', HelpDeskEmailAddress::STATUS_WAITING);

        // Nothing was sent, and nothing could be: only a person writing to their own support
        // address can put a message through the forwarding rule.
        $this->assertNull($workspace->run(fn () => HelpDeskEmailAddress::find($address->id))->verified_at);

        app(InboundEmailIngestor::class)->ingest([
            'delivered_to' => [$inbox->inbound_address],
            'to' => ['support@company.com'],
            'from' => ['email' => 'dana@example.com'],
            'subject' => 'Testing the forward',
            'message_id' => '<setup-1@mail.example>',
        ]);

        $this->assertSame(
            HelpDeskEmailAddress::STATUS_CONNECTED,
            $workspace->run(fn () => HelpDeskEmailAddress::find($address->id))->status,
        );
    }

    public function test_continue_past_step_three_does_not_wait_for_mail(): void
    {
        [$owner, $workspace] = $this->owner();
        $space = $this->freshSpace($workspace, $owner);
        $this->nameInbox($owner, $space);
        $this->addAddress($owner, $space, 'support@company.com');

        $this->actingAs($owner->fresh())
            ->postJson(route('help-desk.spaces.setup.connect', $space))
            ->assertOk()
            ->assertJsonPath('space.setup_step', HelpDeskSpace::STEP_TEAM);

        // Marked read, not proven: proof may arrive days later, and a step that could only be
        // completed by mail from outside would be a wizard nobody finishes.
        $this->assertSame(
            HelpDeskEmailAddress::STATUS_SETUP_REQUIRED,
            $workspace->run(fn () => HelpDeskEmailAddress::query()->firstOrFail())->status,
        );
    }

    // ---- step 4 ------------------------------------------------------------------------------------

    public function test_step_four_adds_an_existing_workspace_member_to_this_space_only(): void
    {
        [$owner, $workspace] = $this->owner();
        $space = $this->freshSpace($workspace, $owner);
        $this->nameInbox($owner, $space);

        $colleague = $this->member($workspace, 'member', 'priya@company.com');
        $inbox = $workspace->run(fn () => HelpDeskInbox::query()->where('help_desk_space_id', $space->id)->firstOrFail());

        $this->actingAs($owner->fresh())
            ->postJson(route('help-desk.spaces.setup.team', $space), [
                'email' => 'priya@company.com', 'role' => HelpDeskMember::ROLE_AGENT,
            ])->assertOk();

        $member = $this->membershipOf($workspace, $colleague);

        $this->assertSame(HelpDeskMember::ROLE_AGENT, $member->role);
        // The flow's access rule: this space's inboxes, and nothing beyond them.
        $this->assertSame([$inbox->id], $workspace->run(fn () => $member->inboxes()->pluck('help_desk_inboxes.id')->all()));
    }

    public function test_step_four_invites_somebody_who_is_not_in_the_workspace_yet(): void
    {
        [$owner, $workspace] = $this->owner();
        $space = $this->freshSpace($workspace, $owner);
        $this->nameInbox($owner, $space);

        $this->actingAs($owner->fresh())
            ->postJson(route('help-desk.spaces.setup.team', $space), [
                'email' => 'david@company.com', 'role' => HelpDeskMember::ROLE_MANAGER,
            ])->assertOk()->assertJsonPath('invited', 'david@company.com');

        // One invitation pipeline, not two (H11): the workspace's own invitation carries it.
        $this->assertTrue(
            WorkspaceInvitation::query()->where('email', 'david@company.com')->exists(),
        );
    }

    public function test_a_help_desk_admin_cannot_be_created_by_somebody_who_is_not_a_workspace_admin(): void
    {
        [$owner, $workspace] = $this->owner();
        $space = $this->freshSpace($workspace, $owner);
        $this->nameInbox($owner, $space);

        $deskAdmin = $this->member($workspace, 'member', 'desk-admin@example.com');
        $this->addToHelpDesk($workspace, $owner, $deskAdmin, HelpDeskMember::ROLE_ADMIN);

        // H10's asymmetry, re-asked here because the wizard is another way in.
        $this->actingAs($deskAdmin->fresh())
            ->postJson(route('help-desk.spaces.setup.team', $space), [
                'email' => 'someone@company.com', 'role' => HelpDeskMember::ROLE_ADMIN,
            ])->assertStatus(422)->assertJsonValidationErrors('role');
    }

    // ---- finishing and resuming ---------------------------------------------------------------------

    public function test_finishing_completes_the_space_and_lands_on_its_inbox(): void
    {
        [$owner, $workspace] = $this->owner();
        $space = $this->freshSpace($workspace, $owner);
        $this->nameInbox($owner, $space);
        $this->addAddress($owner, $space, 'support@company.com');

        $inbox = $workspace->run(fn () => HelpDeskInbox::query()->where('help_desk_space_id', $space->id)->firstOrFail());

        $this->actingAs($owner->fresh())
            ->postJson(route('help-desk.spaces.setup.finish', $space))
            ->assertOk()
            ->assertJsonPath('redirect', route('help-desk.inboxes.addresses', $inbox));

        $this->assertTrue(session()->has('status'));
        $this->assertTrue($workspace->run(fn () => HelpDeskSpace::find($space->id))->isSetUp());

        // And the list swaps the primary action for the finished space.
        $rows = collect($this->actingAs($owner->fresh())->get(route('help-desk.spaces'))
            ->assertOk()->viewData('bootstrap')['spaces'])->keyBy('name');

        $this->assertTrue($rows['Partner Support']['setup_complete']);
        $this->assertSame(route('help-desk.inboxes.addresses', $inbox), $rows['Partner Support']['inbox_url']);
    }

    public function test_leaving_half_way_resumes_at_the_first_incomplete_step(): void
    {
        [$owner, $workspace] = $this->owner();
        $space = $this->freshSpace($workspace, $owner);

        $this->nameInbox($owner, $space);
        $this->addAddress($owner, $space, 'support@company.com');
        $this->actingAs($owner->fresh())->postJson(route('help-desk.spaces.setup.connect', $space))->assertOk();

        // Coming back later: step 4, not step 1, and everything already entered is still there.
        $bootstrap = $this->actingAs($owner->fresh())->get(route('help-desk.spaces.setup', $space))
            ->assertOk()->viewData('bootstrap');

        $this->assertSame(HelpDeskSpace::STEP_TEAM, $bootstrap['space']['setup_step']);
        $this->assertSame('Customer Support', $bootstrap['inbox']['name']);
        $this->assertCount(1, $bootstrap['addresses']);
    }

    public function test_progress_never_runs_backwards(): void
    {
        [$owner, $workspace] = $this->owner();
        $space = $this->freshSpace($workspace, $owner);

        $this->nameInbox($owner, $space);
        $this->addAddress($owner, $space, 'support@company.com');
        $this->actingAs($owner->fresh())->postJson(route('help-desk.spaces.setup.connect', $space))->assertOk();

        // Walking back to step 1 to change the name is an edit, not an undo.
        $this->nameInbox($owner, $space, 'Customer Experience');

        $this->assertSame(
            HelpDeskSpace::STEP_TEAM,
            $workspace->run(fn () => HelpDeskSpace::find($space->id))->setup_step,
        );
    }
}
