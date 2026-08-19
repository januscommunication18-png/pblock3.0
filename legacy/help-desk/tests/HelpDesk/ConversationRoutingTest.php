<?php

namespace Tests\Feature\HelpDesk;

use App\Models\HelpDeskActivity;
use App\Models\HelpDeskConversation;
use App\Models\HelpDeskInbox;
use App\Models\HelpDeskMember;
use App\Models\User;
use App\Models\Workspace;
use App\Services\HelpDesk\InboundEmailIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Manual routing between inboxes (docs/features/help-desk.md — Phase 2, slice 3: FR-2.8),
 * and the inbox-scoped visibility the phase's fourth acceptance criterion requires
 * ("unauthorized members cannot view inbox content", FR-2.6).
 */
class ConversationRoutingTest extends HelpDeskTestCase
{
    use RefreshDatabase;

    /**
     * A second inbox, receiving at a known address.
     *
     * The address is force-filled rather than passed to `create`: it is generated and read-only
     * in the application (Inbound Email requirements §2), and these tests need to know what it
     * is so they can address mail to it.
     */
    private function inbox(Workspace $workspace, string $name, string $address): HelpDeskInbox
    {
        $helpDesk = $this->helpDesk($workspace);

        return $workspace->run(function () use ($workspace, $helpDesk, $name, $address) {
            $inbox = HelpDeskInbox::create([
                'tenant_id' => $workspace->id,
                'help_desk_id' => $helpDesk->id,
                'name' => $name,
            ]);

            $inbox->forceFill(['inbound_address' => $address])->save();

            return $inbox->fresh();
        });
    }

    /** The default inbox, receiving at a real address. */
    private function support(Workspace $workspace): HelpDeskInbox
    {
        $inbox = $this->firstInbox($workspace);

        return $workspace->run(function () use ($inbox) {
            $inbox->forceFill(['inbound_address' => 'support@acme.example'])->save();

            return $inbox->fresh();
        });
    }

    /**
     * A Help Desk Admin — somebody who can reach every inbox AND has an operational role.
     *
     * The WORKSPACE owner deliberately cannot move conversations: administering the desk is not
     * working in it (decision H6/H23), and moving is customer work. So the tests below that need
     * a mover with reach use this rather than the owner.
     */
    private function deskAdmin(Workspace $workspace, User $owner): User
    {
        $user = $this->member($workspace, 'member', 'desk-admin@example.com');
        $this->addToHelpDesk($workspace, $owner, $user, HelpDeskMember::ROLE_ADMIN);

        return $user->fresh();
    }

    /** One conversation, arrived by email like a real one. */
    private function conversation(Workspace $workspace, string $to = 'support@acme.example'): HelpDeskConversation
    {
        return app(InboundEmailIngestor::class)->ingest([
            'to' => [$to],
            'from' => ['email' => 'customer@example.com', 'name' => 'Dana Customer'],
            'subject' => 'Wrong department',
            'text' => 'I think this is a billing question.',
            'message_id' => '<'.uniqid('m', true).'@mail.example>',
        ]);
    }

    // ---- moving (FR-2.8) --------------------------------------------------------------------

    public function test_an_agent_can_move_a_conversation_between_inboxes_they_can_open(): void
    {
        [$owner, $workspace] = $this->owner();
        $support = $this->support($workspace);
        $billing = $this->inbox($workspace, 'Billing', 'billing@acme.example');

        $agent = $this->member($workspace, 'member', 'agent@example.com');
        $this->addToHelpDesk($workspace, $owner, $agent, HelpDeskMember::ROLE_AGENT, [$support->id, $billing->id]);

        $conversation = $this->conversation($workspace);

        $this->actingAs($agent->fresh())
            ->patchJson(route('help-desk.conversations.move', $conversation), ['inbox_id' => $billing->id])
            ->assertOk()->assertJsonPath('inbox', 'Billing');

        $moved = $workspace->run(fn () => HelpDeskConversation::find($conversation->id));
        $this->assertSame($billing->id, $moved->help_desk_inbox_id);
        // The number is how people refer to a case out loud; refiling must not renumber it.
        $this->assertSame($conversation->number, $moved->number);
    }

    public function test_a_move_into_an_inbox_the_mover_cannot_open_is_refused(): void
    {
        [$owner, $workspace] = $this->owner();
        $support = $this->support($workspace);
        $billing = $this->inbox($workspace, 'Billing', 'billing@acme.example');

        $agent = $this->member($workspace, 'member', 'agent@example.com');
        $this->addToHelpDesk($workspace, $owner, $agent, HelpDeskMember::ROLE_AGENT, [$support->id]);

        $conversation = $this->conversation($workspace);

        // Moving something into an inbox you cannot open is a way to hide it.
        $this->actingAs($agent->fresh())
            ->patchJson(route('help-desk.conversations.move', $conversation), ['inbox_id' => $billing->id])
            ->assertForbidden();

        $this->assertSame($support->id, $workspace->run(fn () => HelpDeskConversation::find($conversation->id))->help_desk_inbox_id);
    }

    public function test_a_move_out_of_an_inbox_the_mover_cannot_open_is_refused(): void
    {
        [$owner, $workspace] = $this->owner();
        $this->support($workspace);
        $billing = $this->inbox($workspace, 'Billing', 'billing@acme.example');

        $agent = $this->member($workspace, 'member', 'agent@example.com');
        $this->addToHelpDesk($workspace, $owner, $agent, HelpDeskMember::ROLE_AGENT, [$billing->id]);

        $conversation = $this->conversation($workspace);

        // …and moving one OUT of an inbox you cannot open is a way to touch what is in it.
        $this->actingAs($agent->fresh())
            ->patchJson(route('help-desk.conversations.move', $conversation), ['inbox_id' => $billing->id])
            ->assertForbidden();
    }

    public function test_a_viewer_may_not_move_anything(): void
    {
        [$owner, $workspace] = $this->owner();
        $support = $this->support($workspace);
        $billing = $this->inbox($workspace, 'Billing', 'billing@acme.example');

        $viewer = $this->member($workspace, 'member', 'viewer@example.com');
        $this->addToHelpDesk($workspace, $owner, $viewer, HelpDeskMember::ROLE_VIEWER, [$support->id, $billing->id]);

        $conversation = $this->conversation($workspace);

        // Read-only is read-only; filing is not reading.
        $this->actingAs($viewer->fresh())
            ->patchJson(route('help-desk.conversations.move', $conversation), ['inbox_id' => $billing->id])
            ->assertForbidden();
    }

    public function test_an_assignee_who_cannot_follow_the_conversation_is_cleared(): void
    {
        [$owner, $workspace] = $this->owner();
        $support = $this->support($workspace);
        $billing = $this->inbox($workspace, 'Billing', 'billing@acme.example');

        // Assigned to somebody who only has the Support inbox…
        $agent = $this->member($workspace, 'member', 'agent@example.com');
        $membership = $this->addToHelpDesk($workspace, $owner, $agent, HelpDeskMember::ROLE_AGENT, [$support->id]);
        $workspace->run(fn () => $support->forceFill(['default_assignee_id' => $membership->id])->save());

        $conversation = $this->conversation($workspace);
        $this->assertSame($membership->id, $conversation->assignee_id);

        // …moved by a Help Desk admin into an inbox they cannot open.
        $this->actingAs($this->deskAdmin($workspace, $owner))
            ->patchJson(route('help-desk.conversations.move', $conversation), ['inbox_id' => $billing->id])
            ->assertOk();

        // An assignment they can neither see nor act on is not an assignment.
        $this->assertNull($workspace->run(fn () => HelpDeskConversation::find($conversation->id))->assignee_id);
    }

    public function test_an_assignee_who_can_follow_it_keeps_it(): void
    {
        [$owner, $workspace] = $this->owner();
        $support = $this->support($workspace);
        $billing = $this->inbox($workspace, 'Billing', 'billing@acme.example');

        $agent = $this->member($workspace, 'member', 'agent@example.com');
        $membership = $this->addToHelpDesk($workspace, $owner, $agent, HelpDeskMember::ROLE_AGENT, [$support->id, $billing->id]);
        $workspace->run(fn () => $support->forceFill(['default_assignee_id' => $membership->id])->save());

        $conversation = $this->conversation($workspace);

        $this->actingAs($this->deskAdmin($workspace, $owner))
            ->patchJson(route('help-desk.conversations.move', $conversation), ['inbox_id' => $billing->id])
            ->assertOk();

        // Taking a case away from the person handling it because it was refiled drops work.
        $this->assertSame($membership->id, $workspace->run(fn () => HelpDeskConversation::find($conversation->id))->assignee_id);
    }

    public function test_moving_into_an_inbox_with_a_default_assignee_picks_it_up(): void
    {
        [$owner, $workspace] = $this->owner();
        $this->support($workspace);
        $billing = $this->inbox($workspace, 'Billing', 'billing@acme.example');

        $agent = $this->member($workspace, 'member', 'agent@example.com');
        $membership = $this->addToHelpDesk($workspace, $owner, $agent, HelpDeskMember::ROLE_AGENT, [$billing->id]);
        $workspace->run(fn () => $billing->forceFill(['default_assignee_id' => $membership->id])->save());

        $conversation = $this->conversation($workspace);
        $this->assertNull($conversation->assignee_id);

        $this->actingAs($this->deskAdmin($workspace, $owner))
            ->patchJson(route('help-desk.conversations.move', $conversation), ['inbox_id' => $billing->id])
            ->assertOk();

        $this->assertSame($membership->id, $workspace->run(fn () => HelpDeskConversation::find($conversation->id))->assignee_id);
    }

    public function test_moving_a_conversation_is_recorded(): void
    {
        [$owner, $workspace] = $this->owner();
        $this->support($workspace);
        $billing = $this->inbox($workspace, 'Billing', 'billing@acme.example');
        $conversation = $this->conversation($workspace);

        $this->actingAs($this->deskAdmin($workspace, $owner))
            ->patchJson(route('help-desk.conversations.move', $conversation), ['inbox_id' => $billing->id])
            ->assertOk();

        $entry = $workspace->run(fn () => HelpDeskActivity::query()
            ->where('event', HelpDeskActivity::EVENT_CONVERSATION_MOVED)->firstOrFail());

        $this->assertSame('moved #1 from Support to Billing', $entry->sentence());
    }

    public function test_moving_it_where_it_already_is_changes_nothing(): void
    {
        [$owner, $workspace] = $this->owner();
        $support = $this->support($workspace);
        $this->inbox($workspace, 'Billing', 'billing@acme.example');
        $conversation = $this->conversation($workspace);

        $this->actingAs($this->deskAdmin($workspace, $owner))
            ->patchJson(route('help-desk.conversations.move', $conversation), ['inbox_id' => $support->id])
            ->assertOk();

        // A double-click is not a mistake worth an error — nor worth an activity entry.
        $this->assertSame(0, $workspace->run(fn () => HelpDeskActivity::query()
            ->where('event', HelpDeskActivity::EVENT_CONVERSATION_MOVED)->count()));
    }

    public function test_a_conversation_from_another_workspace_is_not_addressable(): void
    {
        [$ownerA, $workspaceA] = $this->owner('acme-inc');
        [$ownerB, $workspaceB] = $this->owner('globex');
        $this->support($workspaceA);
        $mine = $this->inbox($workspaceA, 'Billing', 'billing@acme.example');

        $theirInbox = $this->firstInbox($workspaceB);
        $workspaceB->run(fn () => $theirInbox->forceFill(['inbound_address' => 'support@globex.example'])->save());
        $theirs = $this->conversation($workspaceB, 'support@globex.example');

        $this->actingAs($this->deskAdmin($workspaceA, $ownerA))
            ->patchJson(route('help-desk.conversations.move', $theirs), ['inbox_id' => $mine->id])
            ->assertNotFound();
    }

    // ---- inbox-scoped visibility (FR-2.6, acceptance criterion 4) -----------------------------

    public function test_the_list_shows_only_conversations_in_inboxes_you_can_open(): void
    {
        [$owner, $workspace] = $this->owner();
        $support = $this->support($workspace);
        $this->inbox($workspace, 'Billing', 'billing@acme.example');

        $this->conversation($workspace);                                // Support
        $this->conversation($workspace, 'billing@acme.example');        // Billing

        $agent = $this->member($workspace, 'member', 'agent@example.com');
        $this->addToHelpDesk($workspace, $owner, $agent, HelpDeskMember::ROLE_AGENT, [$support->id]);

        $bootstrap = $this->actingAs($agent->fresh())->get(route('help-desk.conversations'))
            ->assertOk()->viewData('bootstrap');

        $this->assertCount(1, $bootstrap['conversations']);
        $this->assertSame('Support', $bootstrap['conversations'][0]['inbox']);
        // And the filter offers only what they can open, so the screen cannot suggest otherwise.
        $this->assertCount(1, $bootstrap['inboxes']);
    }

    public function test_a_manager_sees_every_inbox_including_later_ones(): void
    {
        [$owner, $workspace] = $this->owner();
        $this->support($workspace);
        $manager = $this->member($workspace, 'member', 'manager@example.com');
        $this->addToHelpDesk($workspace, $owner, $manager, HelpDeskMember::ROLE_MANAGER);

        $this->conversation($workspace);
        $this->inbox($workspace, 'Billing', 'billing@acme.example');
        $this->conversation($workspace, 'billing@acme.example');

        $bootstrap = $this->actingAs($manager->fresh())->get(route('help-desk.conversations'))
            ->assertOk()->viewData('bootstrap');

        $this->assertCount(2, $bootstrap['conversations']);
    }

    public function test_a_workspace_admin_who_is_not_a_member_sees_no_conversations(): void
    {
        [$owner, $workspace] = $this->owner();
        $this->support($workspace);
        $this->conversation($workspace);

        // They administer the desk (H6); they do not work in it. An empty list is the honest
        // answer to "what can I open here?".
        $bootstrap = $this->actingAs($owner->fresh())->get(route('help-desk.conversations'))
            ->assertOk()->viewData('bootstrap');

        $this->assertSame([], $bootstrap['conversations']);
        $this->assertSame([], $bootstrap['inboxes']);
    }

    public function test_somebody_with_no_help_desk_access_cannot_reach_the_list(): void
    {
        [$owner, $workspace] = $this->owner();
        $this->support($workspace);
        $outsider = $this->member($workspace, 'member', 'outsider@example.com');

        $this->actingAs($outsider->fresh())->get(route('help-desk.conversations'))->assertNotFound();
    }
}
