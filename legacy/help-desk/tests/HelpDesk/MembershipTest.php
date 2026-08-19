<?php

namespace Tests\Feature\HelpDesk;

use App\Models\HelpDesk;
use App\Models\HelpDeskInbox;
use App\Models\HelpDeskMember;
use App\Services\HelpDesk\HelpDeskAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Help Desk membership, roles, inbox access and deactivation
 * (docs/features/help-desk.md — Phase 1, slice 2: FR-1.4, FR-1.6, FR-1.7, FR-1.8).
 *
 * The rule under test throughout is §5's: workspace role, project role, Help Desk role and
 * inbox access are evaluated independently and the most restrictive applicable permission wins.
 */
class MembershipTest extends HelpDeskTestCase
{
    use RefreshDatabase;

    // ---- provisioning ------------------------------------------------------------------------

    public function test_the_first_administrator_visit_provisions_a_help_desk_and_an_inbox(): void
    {
        [$owner, $workspace] = $this->owner();

        $this->assertSame(0, $workspace->run(fn () => HelpDesk::query()->count()));

        // Provisioned on the way past — a fresh Help Desk sends its administrator to the
        // wizard, and it has to exist for the wizard to have anything to configure (FR-1.3).
        $this->actingAs($owner)->get('/help-desk')->assertRedirect(route('help-desk.setup'));

        $this->assertSame(1, $workspace->run(fn () => HelpDesk::query()->count()));
        // An inbox exists from the start, because inbox-level access needs one to grant.
        $this->assertSame(config('help-desk.default_inbox'), $this->firstInbox($workspace)->name);
    }

    public function test_provisioning_happens_once_however_often_it_is_asked_for(): void
    {
        [$owner, $workspace] = $this->owner();

        $this->actingAs($owner)->get('/help-desk')->assertRedirect();
        $this->actingAs($owner->fresh())->get('/help-desk')->assertRedirect();

        $this->assertSame(1, $workspace->run(fn () => HelpDesk::query()->count()));
        $this->assertSame(1, $workspace->run(fn () => HelpDeskInbox::query()->count()));
    }

    // ---- membership is what grants access (FR-1.4) -------------------------------------------

    public function test_an_added_member_can_open_the_help_desk_and_sees_it_in_the_rail(): void
    {
        [$owner, $workspace] = $this->owner();
        $agent = $this->member($workspace, 'member', 'agent@example.com');

        $this->actingAs($agent)->get('/help-desk')->assertNotFound();

        $this->addToHelpDesk($workspace, $owner, $agent, HelpDeskMember::ROLE_AGENT);

        $this->actingAs($agent->fresh())->get('/help-desk')->assertOk();
        $this->actingAs($agent->fresh())->get(route('projects.index'))
            ->assertOk()->assertSee(route('help-desk.index'), false);
    }

    public function test_a_help_desk_role_grants_nothing_outside_the_help_desk(): void
    {
        [$owner, $workspace] = $this->owner();
        $admin = $this->member($workspace, 'member', 'hd-admin@example.com');

        // The highest Help Desk role there is, held by an ordinary workspace member.
        $this->addToHelpDesk($workspace, $owner, $admin, HelpDeskMember::ROLE_ADMIN);

        // Workspace settings are still refused: the two role systems are independent (§5).
        $this->actingAs($admin->fresh())->get('/settings/general')->assertForbidden();
        $this->actingAs($admin->fresh())->patchJson('/settings/general', [
            'name' => 'Acme Inc', 'slug' => 'acme-inc', 'company_size' => '2-10', 'timezone' => 'UTC',
        ])->assertForbidden();
    }

    public function test_somebody_outside_the_workspace_cannot_be_added(): void
    {
        [$owner, $workspace] = $this->owner();
        [$stranger] = $this->owner('globex');

        $this->actingAs($owner)->postJson(route('help-desk.members.store'), [
            'user_id' => $stranger->id, 'role' => HelpDeskMember::ROLE_AGENT,
        ])->assertStatus(422)->assertJsonValidationErrors('user_id');
    }

    public function test_the_same_person_cannot_be_added_twice(): void
    {
        [$owner, $workspace] = $this->owner();
        $agent = $this->member($workspace, 'member', 'agent@example.com');
        $this->addToHelpDesk($workspace, $owner, $agent);

        $this->actingAs($owner->fresh())->postJson(route('help-desk.members.store'), [
            'user_id' => $agent->id, 'role' => HelpDeskMember::ROLE_VIEWER,
        ])->assertStatus(422)->assertJsonValidationErrors('user_id');
    }

    // ---- who may manage members --------------------------------------------------------------

    public function test_a_workspace_admin_may_manage_members_without_a_help_desk_role(): void
    {
        [, $workspace] = $this->owner();
        $wsAdmin = $this->member($workspace, 'admin', 'ws-admin@example.com');
        $agent = $this->member($workspace, 'member', 'agent@example.com');

        // Somebody has to be able to add the first member (decision H6) — and doing so gives
        // them no Help Desk role of their own.
        $this->actingAs($wsAdmin)->get(route('help-desk.members'))->assertOk();
        $this->actingAs($wsAdmin->fresh())->postJson(route('help-desk.members.store'), [
            'user_id' => $agent->id, 'role' => HelpDeskMember::ROLE_AGENT,
        ])->assertOk();

        $this->assertNull($this->membershipOf($workspace, $wsAdmin));
    }

    public function test_a_help_desk_admin_may_manage_members(): void
    {
        [$owner, $workspace] = $this->owner();
        $hdAdmin = $this->member($workspace, 'member', 'hd-admin@example.com');
        $agent = $this->member($workspace, 'member', 'agent@example.com');
        $this->addToHelpDesk($workspace, $owner, $hdAdmin, HelpDeskMember::ROLE_ADMIN);

        $this->actingAs($hdAdmin->fresh())->postJson(route('help-desk.members.store'), [
            'user_id' => $agent->id, 'role' => HelpDeskMember::ROLE_AGENT,
        ])->assertOk();
    }

    public function test_an_agent_may_not_manage_members(): void
    {
        [$owner, $workspace] = $this->owner();
        $agent = $this->member($workspace, 'member', 'agent@example.com');
        $other = $this->member($workspace, 'member', 'other@example.com');
        $this->addToHelpDesk($workspace, $owner, $agent, HelpDeskMember::ROLE_AGENT);

        $this->actingAs($agent->fresh())->get(route('help-desk.members'))->assertForbidden();
        $this->actingAs($agent->fresh())->postJson(route('help-desk.members.store'), [
            'user_id' => $other->id, 'role' => HelpDeskMember::ROLE_VIEWER,
        ])->assertForbidden();
    }

    public function test_only_workspace_authority_may_hand_out_help_desk_admin(): void
    {
        [$owner, $workspace] = $this->owner();
        $hdAdmin = $this->member($workspace, 'member', 'hd-admin@example.com');
        $candidate = $this->member($workspace, 'member', 'candidate@example.com');
        $this->addToHelpDesk($workspace, $owner, $hdAdmin, HelpDeskMember::ROLE_ADMIN);

        // A Help Desk Admin making another Admin is a one-way door for whoever set the desk up.
        $this->actingAs($hdAdmin->fresh())->postJson(route('help-desk.members.store'), [
            'user_id' => $candidate->id, 'role' => HelpDeskMember::ROLE_ADMIN,
        ])->assertForbidden();

        $this->actingAs($owner->fresh())->postJson(route('help-desk.members.store'), [
            'user_id' => $candidate->id, 'role' => HelpDeskMember::ROLE_ADMIN,
        ])->assertOk();
    }

    public function test_nobody_manages_their_own_membership(): void
    {
        [$owner, $workspace] = $this->owner();
        $hdAdmin = $this->member($workspace, 'member', 'hd-admin@example.com');
        $membership = $this->addToHelpDesk($workspace, $owner, $hdAdmin, HelpDeskMember::ROLE_ADMIN);

        // Self-demotion and leaving are their own actions, not member management — this is how
        // the last Help Desk Admin removes themselves by accident.
        $this->actingAs($hdAdmin->fresh())
            ->patchJson(route('help-desk.members.update', $membership), ['role' => HelpDeskMember::ROLE_VIEWER])
            ->assertForbidden();

        $this->actingAs($hdAdmin->fresh())
            ->deleteJson(route('help-desk.members.destroy', $membership))
            ->assertForbidden();
    }

    // ---- roles (FR-1.6) ----------------------------------------------------------------------

    public function test_each_role_carries_its_own_abilities(): void
    {
        [$owner, $workspace] = $this->owner();
        $access = app(HelpDeskAccess::class);

        $expectations = [
            HelpDeskMember::ROLE_ADMIN => ['reply' => true, 'note' => true, 'manage_members' => true],
            HelpDeskMember::ROLE_MANAGER => ['reply' => true, 'note' => true, 'manage_members' => false],
            HelpDeskMember::ROLE_AGENT => ['reply' => true, 'note' => true, 'manage_members' => false],
            // §5: internal-only participation; never a customer reply.
            HelpDeskMember::ROLE_COLLABORATOR => ['reply' => false, 'note' => true, 'manage_members' => false],
            HelpDeskMember::ROLE_VIEWER => ['reply' => false, 'note' => false, 'manage_members' => false],
        ];

        foreach ($expectations as $role => $abilities) {
            $user = $this->member($workspace, 'member', "{$role}@example.com");
            $this->addToHelpDesk($workspace, $owner, $user, $role);

            foreach ($abilities as $ability => $expected) {
                $this->assertSame($expected, $access->allows($user, $workspace, $ability),
                    "{$role} should ".($expected ? '' : 'not ')."have {$ability}");
            }
        }
    }

    public function test_a_role_can_be_changed(): void
    {
        [$owner, $workspace] = $this->owner();
        $agent = $this->member($workspace, 'member', 'agent@example.com');
        $membership = $this->addToHelpDesk($workspace, $owner, $agent, HelpDeskMember::ROLE_AGENT);

        $this->actingAs($owner->fresh())
            ->patchJson(route('help-desk.members.update', $membership), ['role' => HelpDeskMember::ROLE_VIEWER])
            ->assertOk();

        $this->assertSame(HelpDeskMember::ROLE_VIEWER, $this->membershipOf($workspace, $agent)->role);
    }

    // ---- inbox-level access (FR-1.7) ---------------------------------------------------------

    public function test_an_agent_reaches_only_the_inboxes_they_are_given(): void
    {
        [$owner, $workspace] = $this->owner();
        $agent = $this->member($workspace, 'member', 'agent@example.com');
        $support = $this->firstInbox($workspace);
        $billing = $workspace->run(fn () => HelpDeskInbox::create([
            'tenant_id' => $workspace->id,
            'help_desk_id' => $support->help_desk_id,
            'name' => 'Billing',
        ]));

        $this->addToHelpDesk($workspace, $owner, $agent, HelpDeskMember::ROLE_AGENT, [$support->id]);

        $access = app(HelpDeskAccess::class);
        $this->assertSame([$support->id], $access->inboxIds($agent, $workspace));
        $this->assertTrue($access->canOpenInbox($agent, $workspace, $support->id));
        $this->assertFalse($access->canOpenInbox($agent, $workspace, $billing->id));
    }

    public function test_admins_and_managers_reach_every_inbox_including_later_ones(): void
    {
        [$owner, $workspace] = $this->owner();
        $manager = $this->member($workspace, 'member', 'manager@example.com');
        $this->addToHelpDesk($workspace, $owner, $manager, HelpDeskMember::ROLE_MANAGER);

        $helpDesk = $this->helpDesk($workspace, $owner);
        $later = $workspace->run(fn () => HelpDeskInbox::create([
            'tenant_id' => $workspace->id, 'help_desk_id' => $helpDesk->id, 'name' => 'Escalations',
        ]));

        // null means "all of them" — a stored list would be right until this inbox was created.
        $this->assertNull(app(HelpDeskAccess::class)->inboxIds($manager, $workspace));
        $this->assertTrue(app(HelpDeskAccess::class)->canOpenInbox($manager, $workspace, $later->id));
    }

    public function test_promoting_somebody_to_a_role_that_sees_everything_clears_their_named_inboxes(): void
    {
        [$owner, $workspace] = $this->owner();
        $agent = $this->member($workspace, 'member', 'agent@example.com');
        $support = $this->firstInbox($workspace);
        $membership = $this->addToHelpDesk($workspace, $owner, $agent, HelpDeskMember::ROLE_AGENT, [$support->id]);

        $this->actingAs($owner->fresh())
            ->patchJson(route('help-desk.members.update', $membership), ['role' => HelpDeskMember::ROLE_MANAGER])
            ->assertOk();

        // Otherwise the rows come back to life if they are ever moved down again.
        $this->assertSame(0, $workspace->run(fn () => $this->membershipOf($workspace, $agent)->inboxes()->count()));
    }

    public function test_an_inbox_from_another_workspace_cannot_be_granted(): void
    {
        [$owner, $workspace] = $this->owner();
        [$otherOwner, $other] = $this->owner('globex');
        $this->helpDesk($other, $otherOwner);
        $foreign = $this->firstInbox($other);

        $agent = $this->member($workspace, 'member', 'agent@example.com');
        $membership = $this->addToHelpDesk($workspace, $owner, $agent, HelpDeskMember::ROLE_AGENT);

        $this->actingAs($owner->fresh())
            ->patchJson(route('help-desk.members.update', $membership), ['inbox_ids' => [$foreign->id]])
            ->assertOk();

        $this->assertSame(0, $workspace->run(fn () => $this->membershipOf($workspace, $agent)->inboxes()->count()));
    }

    // ---- inboxes -----------------------------------------------------------------------------

    public function test_an_administrator_can_create_and_rename_an_inbox(): void
    {
        [$owner, $workspace] = $this->owner();

        $this->actingAs($owner)->postJson(route('help-desk.inboxes.store'), ['name' => 'Billing'])
            ->assertOk()->assertJsonPath('ok', true);

        $billing = $workspace->run(fn () => HelpDeskInbox::query()->where('name', 'Billing')->firstOrFail());

        $this->actingAs($owner->fresh())
            ->patchJson(route('help-desk.inboxes.update', $billing), ['name' => 'Payments'])
            ->assertOk();

        $this->assertSame('Payments', $workspace->run(fn () => HelpDeskInbox::find($billing->id)->name));
    }

    public function test_two_inboxes_cannot_share_a_name(): void
    {
        [$owner] = $this->owner();

        $this->actingAs($owner)->postJson(route('help-desk.inboxes.store'), ['name' => 'Billing'])->assertOk();
        $this->actingAs($owner->fresh())->postJson(route('help-desk.inboxes.store'), ['name' => 'Billing'])
            ->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_an_agent_may_not_create_an_inbox(): void
    {
        [$owner, $workspace] = $this->owner();
        $agent = $this->member($workspace, 'member', 'agent@example.com');
        $this->addToHelpDesk($workspace, $owner, $agent, HelpDeskMember::ROLE_AGENT);

        $this->actingAs($agent->fresh())->postJson(route('help-desk.inboxes.store'), ['name' => 'Billing'])
            ->assertForbidden();
    }

    // ---- deactivation (FR-1.8) ---------------------------------------------------------------

    public function test_a_deactivated_member_loses_access_but_keeps_their_role_and_inboxes(): void
    {
        [$owner, $workspace] = $this->owner();
        $agent = $this->member($workspace, 'member', 'agent@example.com');
        $support = $this->firstInbox($workspace);
        $membership = $this->addToHelpDesk($workspace, $owner, $agent, HelpDeskMember::ROLE_AGENT, [$support->id]);

        $this->actingAs($owner->fresh())
            ->patchJson(route('help-desk.members.update', $membership), ['status' => HelpDeskMember::STATUS_INACTIVE])
            ->assertOk();

        // Access ends immediately — no data has to be rebuilt for it to take effect (§9).
        $this->actingAs($agent->fresh())->get('/help-desk')->assertNotFound();
        $this->assertFalse(app(HelpDeskAccess::class)->allows($agent, $workspace, 'reply'));

        $deactivated = $this->membershipOf($workspace, $agent);
        $this->assertSame(HelpDeskMember::ROLE_AGENT, $deactivated->role);
        $this->assertSame(1, $workspace->run(fn () => $deactivated->inboxes()->count()));

        // …and switching them back on restores exactly what they had.
        $this->actingAs($owner->fresh())
            ->patchJson(route('help-desk.members.update', $membership), ['status' => HelpDeskMember::STATUS_ACTIVE])
            ->assertOk();

        $this->actingAs($agent->fresh())->get('/help-desk')->assertOk();
    }

    // ---- removal (acceptance criterion 5) -----------------------------------------------------

    public function test_removing_a_member_keeps_their_record_and_re_adding_restores_it(): void
    {
        [$owner, $workspace] = $this->owner();
        $agent = $this->member($workspace, 'member', 'agent@example.com');
        $membership = $this->addToHelpDesk($workspace, $owner, $agent, HelpDeskMember::ROLE_AGENT);

        $this->actingAs($owner->fresh())
            ->deleteJson(route('help-desk.members.destroy', $membership))->assertOk();

        $this->actingAs($agent->fresh())->get('/help-desk')->assertNotFound();

        // Soft deleted, because their replies, notes and assignments point at this row.
        $removed = $this->membershipOf($workspace, $agent);
        $this->assertNotNull($removed);
        $this->assertNotNull($removed->deleted_at);

        $this->actingAs($owner->fresh())->postJson(route('help-desk.members.store'), [
            'user_id' => $agent->id, 'role' => HelpDeskMember::ROLE_VIEWER,
        ])->assertOk();

        // The SAME row comes back, so the history stays attached to the member it belongs to.
        $restored = $this->membershipOf($workspace, $agent);
        $this->assertSame($membership->id, $restored->id);
        $this->assertNull($restored->deleted_at);
        $this->assertSame(HelpDeskMember::ROLE_VIEWER, $restored->role);
    }

    // ---- navigation (FR-1.2) -----------------------------------------------------------------

    public function test_the_help_desk_area_gets_its_own_sidebar(): void
    {
        [$owner, $workspace] = $this->owner();
        $this->helpDesk($workspace, $owner);

        // A different room, not the same room with extra doors: the workspace's project
        // navigation has no place in a support agent's sidebar.
        $this->actingAs($owner)->get('/help-desk')
            ->assertOk()
            ->assertSee('Inboxes')
            ->assertDontSee('New work item')
            ->assertDontSee('No projects yet')
            ->assertDontSee('Create your first project')
            ->assertDontSee('Stickies');
    }

    public function test_the_ordinary_sidebar_is_untouched_outside_the_help_desk(): void
    {
        [$owner, $workspace] = $this->owner();
        $this->helpDesk($workspace, $owner);

        $this->actingAs($owner)->get(route('projects.index'))
            ->assertOk()
            ->assertSee('Stickies');
    }

    public function test_the_members_link_appears_only_for_somebody_who_may_manage_them(): void
    {
        [$owner, $workspace] = $this->owner();
        $agent = $this->member($workspace, 'member', 'agent@example.com');
        $this->addToHelpDesk($workspace, $owner, $agent, HelpDeskMember::ROLE_AGENT);

        $this->actingAs($owner->fresh())->get('/help-desk')
            ->assertOk()->assertSee(route('help-desk.members'), false);

        $this->actingAs($agent->fresh())->get('/help-desk')
            ->assertOk()->assertDontSee(route('help-desk.members'), false);
    }

    public function test_the_sidebar_lists_only_the_inboxes_somebody_may_open(): void
    {
        [$owner, $workspace] = $this->owner();
        $agent = $this->member($workspace, 'member', 'agent@example.com');
        $support = $this->firstInbox($workspace);
        $workspace->run(fn () => HelpDeskInbox::create([
            'tenant_id' => $workspace->id, 'help_desk_id' => $support->help_desk_id, 'name' => 'Billing',
        ]));

        $this->addToHelpDesk($workspace, $owner, $agent, HelpDeskMember::ROLE_AGENT, [$support->id]);

        $this->actingAs($agent->fresh())->get('/help-desk')
            ->assertOk()
            ->assertSee($support->name)
            ->assertDontSee('Billing');
    }

    // ---- tenancy (§12: multi-tenant isolation) ------------------------------------------------

    public function test_help_desk_membership_does_not_travel_between_workspaces(): void
    {
        [$ownerA, $workspaceA] = $this->owner('acme-inc');
        [$ownerB, $workspaceB] = $this->owner('globex');

        // The same person, in both workspaces, but a Help Desk member in only one of them.
        $agent = $this->member($workspaceA, 'member', 'agent@example.com');
        $this->join($workspaceB, $agent);
        $this->addToHelpDesk($workspaceA, $ownerA, $agent, HelpDeskMember::ROLE_ADMIN);

        $access = app(HelpDeskAccess::class);
        $this->assertTrue($access->canOpen($agent, $workspaceA));
        $this->assertFalse($access->canOpen($agent, $workspaceB));
        $this->assertFalse($access->canAdminister($agent, $workspaceB));
    }

    public function test_a_membership_from_another_workspace_is_not_addressable(): void
    {
        [$ownerA, $workspaceA] = $this->owner('acme-inc');
        [$ownerB, $workspaceB] = $this->owner('globex');

        $theirs = $this->member($workspaceB, 'member', 'theirs@example.com');
        $foreign = $this->addToHelpDesk($workspaceB, $ownerB, $theirs, HelpDeskMember::ROLE_AGENT);

        // Workspace A's owner, naming workspace B's membership by id.
        $this->actingAs($ownerA)
            ->patchJson(route('help-desk.members.update', $foreign), ['role' => HelpDeskMember::ROLE_ADMIN])
            ->assertNotFound();

        $this->assertSame(HelpDeskMember::ROLE_AGENT, $this->membershipOf($workspaceB, $theirs)->role);
    }
}
