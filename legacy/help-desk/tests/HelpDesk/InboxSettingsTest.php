<?php

namespace Tests\Feature\HelpDesk;

use App\Models\HelpDeskActivity;
use App\Models\HelpDeskInbox;
use App\Models\HelpDeskMember;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Inbox configuration (docs/features/help-desk.md — Phase 2, slice 1:
 * FR-2.1 shared inboxes, FR-2.2 inbound address, FR-2.3 outbound identity,
 * FR-2.5 default assignee, FR-2.6 inbox permissions).
 */
class InboxSettingsTest extends HelpDeskTestCase
{
    use RefreshDatabase;

    public function test_an_administrator_can_create_a_fully_configured_inbox(): void
    {
        [$owner, $workspace] = $this->owner();
        $agent = $this->member($workspace, 'member', 'agent@example.com');
        $membership = $this->addToHelpDesk($workspace, $owner, $agent, HelpDeskMember::ROLE_AGENT);

        $this->actingAs($owner->fresh())->postJson(route('help-desk.inboxes.store'), [
            'name' => 'Billing',
            'outbound_from_name' => 'Acme Billing',
            'outbound_from_address' => 'no-reply@acme.example',
            'default_assignee_id' => $membership->id,
        ])->assertOk();

        $inbox = $workspace->run(fn () => HelpDeskInbox::query()->where('name', 'Billing')->firstOrFail());

        $this->assertSame('Acme Billing', $inbox->outbound_from_name);
        $this->assertSame($membership->id, $inbox->default_assignee_id);
    }

    public function test_every_inbox_is_generated_a_unique_inbound_address(): void
    {
        [$owner, $workspace] = $this->owner();
        $this->helpDesk($workspace, $owner);

        $this->actingAs($owner)->postJson(route('help-desk.inboxes.store'), ['name' => 'Billing'])->assertOk();

        $inbox = $workspace->run(fn () => HelpDeskInbox::query()->where('name', 'Billing')->firstOrFail());
        $support = $this->firstInbox($workspace);

        /*
         * `inbox-{unique-id}@{domain}` — and the inbox's NAME is deliberately absent from it
         * (Setup Inbox flow, step 3). An address built from the name would have to change when
         * the name did, and changing it breaks a forwarding rule in somebody else's mail
         * provider. The id is stored beside the address as a fact of its own.
         */
        $this->assertMatchesRegularExpression(
            '/^inbox-[a-z0-9]{6}@'.preg_quote(config('help-desk.inbound.domain'), '/').'$/',
            (string) $inbox->inbound_address,
        );
        $this->assertStringNotContainsString('billing', (string) $inbox->inbound_address);
        $this->assertSame('inbox-'.$inbox->inbound_id.'@'.config('help-desk.inbound.domain'), $inbox->inbound_address);
        // Including the one provisioning made: an inbox nothing can be forwarded to is not an
        // inbox, so no inbox exists without an address for even one save.
        $this->assertNotEmpty($support->inbound_address);
        $this->assertNotSame($support->inbound_address, $inbox->inbound_address);
    }

    public function test_the_inbound_address_cannot_be_set_or_changed_by_a_request(): void
    {
        [$owner, $workspace] = $this->owner();
        $this->helpDesk($workspace, $owner);

        $this->actingAs($owner)->postJson(route('help-desk.inboxes.store'), [
            'name' => 'Billing', 'inbound_address' => 'billing@acme.example',
        ])->assertOk();

        $inbox = $workspace->run(fn () => HelpDeskInbox::query()->where('name', 'Billing')->firstOrFail());
        $generated = (string) $inbox->inbound_address;

        $this->assertNotSame('billing@acme.example', $generated);

        // Nor on the way back through the configure form. Somebody's mail provider is forwarding
        // to this address; a field that could repoint it would break that rule silently (§2).
        $this->actingAs($owner->fresh())->patchJson(route('help-desk.inboxes.update', $inbox), [
            'name' => 'Billing', 'inbound_address' => 'billing@acme.example',
        ])->assertOk();

        $this->assertSame($generated, $workspace->run(fn () => HelpDeskInbox::find($inbox->id)->inbound_address));
    }

    public function test_renaming_an_inbox_leaves_its_address_alone(): void
    {
        [$owner, $workspace] = $this->owner();
        $this->helpDesk($workspace, $owner);

        $this->actingAs($owner)->postJson(route('help-desk.inboxes.store'), ['name' => 'Billing'])->assertOk();

        $inbox = $workspace->run(fn () => HelpDeskInbox::query()->where('name', 'Billing')->firstOrFail());
        $generated = (string) $inbox->inbound_address;

        $this->actingAs($owner->fresh())->patchJson(route('help-desk.inboxes.update', $inbox), [
            'name' => 'Billing and payments',
        ])->assertOk();

        $fresh = $workspace->run(fn () => HelpDeskInbox::find($inbox->id));

        // The address is readable, not derived: regenerating it on a rename would break every
        // forwarding rule pointing at it, for the sake of a nicer-looking string.
        $this->assertSame('Billing and payments', $fresh->name);
        $this->assertSame($generated, $fresh->inbound_address);
    }

    public function test_an_administrator_can_regenerate_an_inbound_address(): void
    {
        [$owner, $workspace] = $this->owner();
        $this->helpDesk($workspace, $owner);
        $inbox = $this->firstInbox($workspace);
        $before = (string) $inbox->inbound_address;

        $this->actingAs($owner->fresh())
            ->postJson(route('help-desk.inboxes.address.regenerate', $inbox))
            ->assertOk();

        $after = (string) $workspace->run(fn () => HelpDeskInbox::find($inbox->id)->inbound_address);

        $this->assertNotSame($before, $after);

        // Recorded with BOTH addresses: this is the one setting change that silently breaks
        // something outside the application, and "why did mail stop arriving?" is answerable
        // only if the old address was written down.
        $entry = $workspace->run(fn () => HelpDeskActivity::query()
            ->where('event', HelpDeskActivity::EVENT_INBOX_ADDRESS_REGENERATED)->firstOrFail());

        $this->assertSame($before, $entry->meta['from']);
        $this->assertSame($after, $entry->meta['to']);
    }

    public function test_an_agent_cannot_regenerate_an_inbound_address(): void
    {
        [$owner, $workspace] = $this->owner();
        $agent = $this->member($workspace, 'member', 'agent@example.com');
        $this->addToHelpDesk($workspace, $owner, $agent, HelpDeskMember::ROLE_AGENT);
        $inbox = $this->firstInbox($workspace);

        $this->actingAs($agent->fresh())
            ->postJson(route('help-desk.inboxes.address.regenerate', $inbox))
            ->assertForbidden();
    }

    public function test_a_blank_reply_from_address_becomes_null(): void
    {
        [$owner, $workspace] = $this->owner();
        $this->helpDesk($workspace, $owner);

        $this->actingAs($owner)->postJson(route('help-desk.inboxes.store'), [
            'name' => 'Sales', 'outbound_from_address' => '  ',
        ])->assertOk();

        $inbox = $workspace->run(fn () => HelpDeskInbox::query()->where('name', 'Sales')->firstOrFail());

        // "" is not an address: stored as null so the inbox reads as unconfigured rather than
        // as configured with nothing.
        $this->assertNull($inbox->outbound_from_address);
    }

    public function test_a_default_assignee_from_another_help_desk_is_ignored(): void
    {
        [$ownerA, $workspaceA] = $this->owner('acme-inc');
        [$ownerB, $workspaceB] = $this->owner('globex');
        $this->helpDesk($workspaceA, $ownerA);

        $theirs = $this->member($workspaceB, 'member', 'theirs@example.com');
        $foreign = $this->addToHelpDesk($workspaceB, $ownerB, $theirs, HelpDeskMember::ROLE_AGENT);

        $this->actingAs($ownerA->fresh())->postJson(route('help-desk.inboxes.store'), [
            'name' => 'Billing', 'default_assignee_id' => $foreign->id,
        ])->assertOk();

        $inbox = $workspaceA->run(fn () => HelpDeskInbox::query()->where('name', 'Billing')->firstOrFail());
        $this->assertNull($inbox->default_assignee_id);
    }

    public function test_the_sender_falls_back_so_an_inbox_can_always_reply(): void
    {
        [$owner, $workspace] = $this->owner();
        $this->helpDesk($workspace, $owner);

        $this->actingAs($owner)->postJson(route('help-desk.inboxes.store'), ['name' => 'Sales'])->assertOk();

        $inbox = $workspace->run(fn () => HelpDeskInbox::query()->where('name', 'Sales')->firstOrFail());

        // An outbound identity is something an administrator gets around to; a reply that
        // cannot be sent because a field is blank is the worse failure. The generated inbound
        // address is the right fallback: a reply to it comes back into this inbox.
        $this->assertSame(['address' => $inbox->inbound_address, 'name' => 'Sales'], $inbox->sender());
    }

    public function test_the_screen_lists_inboxes_with_what_they_are_configured_for(): void
    {
        [$owner, $workspace] = $this->owner();
        $this->helpDesk($workspace, $owner);

        $this->actingAs($owner)->postJson(route('help-desk.inboxes.store'), ['name' => 'Billing'])->assertOk();

        $inboxes = collect($this->actingAs($owner->fresh())->get(route('help-desk.inboxes'))
            ->assertOk()->viewData('bootstrap')['inboxes'])->keyBy('name');

        $this->assertNotEmpty($inboxes['Billing']['inbound_address']);
        $this->assertSame(0, $inboxes['Billing']['conversations_count']);
        // "0 addresses" and "3 addresses, none of them receiving" are different situations, so
        // the row carries both numbers.
        $this->assertSame(0, $inboxes['Billing']['email_addresses_count']);
        $this->assertSame(0, $inboxes['Billing']['connected_addresses_count']);
    }

    public function test_an_agent_can_neither_see_nor_change_inbox_configuration(): void
    {
        [$owner, $workspace] = $this->owner();
        $agent = $this->member($workspace, 'member', 'agent@example.com');
        $this->addToHelpDesk($workspace, $owner, $agent, HelpDeskMember::ROLE_AGENT);

        $this->actingAs($agent->fresh())->get(route('help-desk.inboxes'))->assertForbidden();
        $this->actingAs($agent->fresh())->postJson(route('help-desk.inboxes.store'), [
            'name' => 'Mine', 'inbound_address' => 'mine@acme.example',
        ])->assertForbidden();
    }

    public function test_an_inbox_from_another_workspace_is_not_addressable(): void
    {
        [$ownerA, $workspaceA] = $this->owner('acme-inc');
        [$ownerB, $workspaceB] = $this->owner('globex');
        $this->helpDesk($workspaceA, $ownerA);
        $theirs = $this->firstInbox($workspaceB);

        $this->actingAs($ownerA)->patchJson(route('help-desk.inboxes.update', $theirs), [
            'name' => 'Taken over',
        ])->assertNotFound();
    }
}
