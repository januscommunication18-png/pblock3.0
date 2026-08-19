<?php

namespace Tests\Feature\HelpDesk;

use App\Models\HelpDeskActivity;
use App\Models\HelpDeskInvite;
use App\Models\HelpDeskMember;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMembership;
use App\Services\WorkspaceInvitationAccepter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

/**
 * Inviting a coworker into the Help Desk (docs/features/help-desk.md — Phase 1, slice 3: FR-1.5).
 *
 * The acceptance criterion under test is the third one: "a new coworker invitation results in
 * Workspace membership PLUS Help Desk membership after acceptance" — one invitation, one link,
 * both memberships (decision H11).
 */
class InviteCoworkerTest extends HelpDeskTestCase
{
    use RefreshDatabase;

    public function test_an_administrator_can_invite_somebody_who_is_not_in_the_workspace(): void
    {
        Mail::fake();
        [$owner, $workspace] = $this->owner();
        $this->helpDesk($workspace, $owner);

        $this->actingAs($owner)->postJson(route('help-desk.invites.store'), [
            'email' => 'new@example.com', 'role' => HelpDeskMember::ROLE_AGENT,
        ])->assertOk()->assertJsonPath('invited', 'new@example.com');

        // One workspace invitation — the link they will click…
        $invitation = $workspace->run(fn () => WorkspaceInvitation::query()->firstOrFail());
        $this->assertSame('new@example.com', $invitation->email);
        $this->assertSame(WorkspaceInvitation::STATUS_PENDING, $invitation->status);

        // …and the Help Desk half of it, recording what they get when they accept.
        $invite = $workspace->run(fn () => HelpDeskInvite::query()->firstOrFail());
        $this->assertSame($invitation->id, $invite->workspace_invitation_id);
        $this->assertSame(HelpDeskMember::ROLE_AGENT, $invite->role);
    }

    public function test_accepting_the_invitation_produces_both_memberships(): void
    {
        Mail::fake();
        [$owner, $workspace] = $this->owner();
        $support = $this->firstInbox($workspace);

        $this->actingAs($owner)->postJson(route('help-desk.invites.store'), [
            'email' => 'new@example.com',
            'role' => HelpDeskMember::ROLE_AGENT,
            'inbox_ids' => [$support->id],
        ])->assertOk();

        $invitation = $workspace->run(fn () => WorkspaceInvitation::query()->firstOrFail());
        $joiner = User::factory()->create(['email' => 'new@example.com', 'current_workspace_id' => null]);

        $result = app(WorkspaceInvitationAccepter::class)->accept($invitation, $joiner);
        $this->assertTrue($result['ok']);

        // Workspace membership…
        $this->assertTrue(WorkspaceMembership::query()
            ->where('workspace_id', $workspace->id)->where('user_id', $joiner->id)
            ->where('status', WorkspaceMembership::STATUS_ACTIVE)->exists());

        // …and Help Desk membership, with the role and inbox access that were chosen for them.
        $membership = $this->membershipOf($workspace, $joiner);
        $this->assertNotNull($membership);
        $this->assertSame(HelpDeskMember::ROLE_AGENT, $membership->role);
        $this->assertSame([$support->id], $workspace->run(fn () => $membership->inboxes()->pluck('help_desk_inboxes.id')->all()));

        // And they can actually get in.
        $this->actingAs($joiner->fresh())->get('/help-desk')->assertOk();
    }

    public function test_the_invite_is_marked_redeemed_and_leaves_the_pending_list(): void
    {
        Mail::fake();
        [$owner, $workspace] = $this->owner();
        $this->helpDesk($workspace, $owner);

        $this->actingAs($owner)->postJson(route('help-desk.invites.store'), [
            'email' => 'new@example.com', 'role' => HelpDeskMember::ROLE_VIEWER,
        ])->assertOk();

        $invitation = $workspace->run(fn () => WorkspaceInvitation::query()->firstOrFail());
        $joiner = User::factory()->create(['email' => 'new@example.com', 'current_workspace_id' => null]);
        app(WorkspaceInvitationAccepter::class)->accept($invitation, $joiner);

        $invite = $workspace->run(fn () => HelpDeskInvite::query()->firstOrFail());
        $this->assertNotNull($invite->redeemed_at);

        $pending = $this->actingAs($owner->fresh())->get(route('help-desk.members'))
            ->assertOk()->viewData('bootstrap')['pending'];
        $this->assertSame([], $pending);
    }

    public function test_accepting_twice_does_not_create_a_second_membership(): void
    {
        Mail::fake();
        [$owner, $workspace] = $this->owner();
        $this->helpDesk($workspace, $owner);

        $this->actingAs($owner)->postJson(route('help-desk.invites.store'), [
            'email' => 'new@example.com', 'role' => HelpDeskMember::ROLE_AGENT,
        ])->assertOk();

        $invitation = $workspace->run(fn () => WorkspaceInvitation::query()->firstOrFail());
        $joiner = User::factory()->create(['email' => 'new@example.com', 'current_workspace_id' => null]);

        $accepter = app(WorkspaceInvitationAccepter::class);
        $accepter->accept($invitation, $joiner);
        // A refresh, a double submit, a retry — §9's "background job fails and retries" applied
        // to the one path a person can re-run by hand.
        $accepter->accept($invitation->fresh(), $joiner->fresh());

        $this->assertSame(1, $workspace->run(fn () => HelpDeskMember::withTrashed()
            ->where('user_id', $joiner->id)->count()));
    }

    public function test_inviting_an_existing_workspace_member_is_refused_with_a_reason(): void
    {
        Mail::fake();
        [$owner, $workspace] = $this->owner();
        $this->helpDesk($workspace, $owner);
        $this->member($workspace, 'member', 'inside@example.com');

        $this->actingAs($owner->fresh())->postJson(route('help-desk.invites.store'), [
            'email' => 'inside@example.com', 'role' => HelpDeskMember::ROLE_AGENT,
        ])->assertStatus(422)->assertJsonValidationErrors('email');

        $this->assertSame(0, $workspace->run(fn () => HelpDeskInvite::query()->count()));
    }

    public function test_an_agent_may_not_invite_anybody(): void
    {
        Mail::fake();
        [$owner, $workspace] = $this->owner();
        $agent = $this->member($workspace, 'member', 'agent@example.com');
        $this->addToHelpDesk($workspace, $owner, $agent, HelpDeskMember::ROLE_AGENT);

        $this->actingAs($agent->fresh())->postJson(route('help-desk.invites.store'), [
            'email' => 'new@example.com', 'role' => HelpDeskMember::ROLE_AGENT,
        ])->assertForbidden();
    }

    public function test_only_workspace_authority_may_invite_somebody_as_a_help_desk_admin(): void
    {
        Mail::fake();
        [$owner, $workspace] = $this->owner();
        $hdAdmin = $this->member($workspace, 'member', 'hd-admin@example.com');
        $this->addToHelpDesk($workspace, $owner, $hdAdmin, HelpDeskMember::ROLE_ADMIN);

        $this->actingAs($hdAdmin->fresh())->postJson(route('help-desk.invites.store'), [
            'email' => 'new@example.com', 'role' => HelpDeskMember::ROLE_ADMIN,
        ])->assertForbidden();
    }

    public function test_revoking_an_invitation_kills_the_link(): void
    {
        Mail::fake();
        [$owner, $workspace] = $this->owner();
        $this->helpDesk($workspace, $owner);

        $this->actingAs($owner)->postJson(route('help-desk.invites.store'), [
            'email' => 'new@example.com', 'role' => HelpDeskMember::ROLE_AGENT,
        ])->assertOk();

        $invite = $workspace->run(fn () => HelpDeskInvite::query()->firstOrFail());

        $this->actingAs($owner->fresh())
            ->deleteJson(route('help-desk.invites.destroy', $invite))
            ->assertOk()->assertJsonPath('pending', []);

        // The workspace invitation is revoked too — deleting only the Help Desk row would leave
        // a live link that still admits somebody to the workspace.
        $invitation = $workspace->run(fn () => WorkspaceInvitation::query()->firstOrFail());
        $this->assertSame(WorkspaceInvitation::STATUS_REVOKED, $invitation->status);

        $joiner = User::factory()->create(['email' => 'new@example.com', 'current_workspace_id' => null]);
        $result = app(WorkspaceInvitationAccepter::class)->accept($invitation, $joiner);
        $this->assertFalse($result['ok']);
        $this->assertNull($this->membershipOf($workspace, $joiner));
    }

    public function test_an_invitation_from_another_workspace_is_not_addressable(): void
    {
        Mail::fake();
        [$ownerA, $workspaceA] = $this->owner('acme-inc');
        [$ownerB, $workspaceB] = $this->owner('globex');
        $this->helpDesk($workspaceA, $ownerA);
        $this->helpDesk($workspaceB, $ownerB);

        $this->actingAs($ownerB)->postJson(route('help-desk.invites.store'), [
            'email' => 'new@example.com', 'role' => HelpDeskMember::ROLE_AGENT,
        ])->assertOk();

        $theirs = $workspaceB->run(fn () => HelpDeskInvite::query()->firstOrFail());

        $this->actingAs($ownerA->fresh())
            ->deleteJson(route('help-desk.invites.destroy', $theirs))
            ->assertNotFound();
    }

    public function test_inviting_and_accepting_are_both_recorded(): void
    {
        Mail::fake();
        [$owner, $workspace] = $this->owner();
        $this->helpDesk($workspace, $owner);

        $this->actingAs($owner)->postJson(route('help-desk.invites.store'), [
            'email' => 'new@example.com', 'role' => HelpDeskMember::ROLE_AGENT,
        ])->assertOk();

        $invitation = $workspace->run(fn () => WorkspaceInvitation::query()->firstOrFail());
        $joiner = User::factory()->create(['email' => 'new@example.com', 'current_workspace_id' => null]);
        app(WorkspaceInvitationAccepter::class)->accept($invitation, $joiner);

        $events = $workspace->run(fn () => HelpDeskActivity::query()->pluck('event')->all());
        $this->assertContains(HelpDeskActivity::EVENT_MEMBER_INVITED, $events);
        $this->assertContains(HelpDeskActivity::EVENT_INVITE_ACCEPTED, $events);
    }
}
