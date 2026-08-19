<?php

namespace Tests\Feature\HelpDesk;

use App\Models\HelpDeskActivity;
use App\Models\HelpDeskEmailAddress;
use App\Models\HelpDeskInbox;
use App\Models\HelpDeskMember;
use App\Models\Workspace;
use App\Services\HelpDesk\InboundEmailIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Connecting customer-facing addresses to an inbox
 * (Inbound Email requirements §4 navigation, §5 the page, §7 status, §8 verification,
 * §9 saving, §10 the list).
 *
 * The acceptance criteria this file covers, one test each:
 *   "Clicking New Email Address no longer opens a modal" / "opens a dedicated full page" /
 *   "The page has its own URL" — the page tests below, which reach it by URL and nothing else.
 *   "Users can copy the generated inbound address" / "Users receive clear forwarding
 *   instructions" — both are on the payload the page is rendered from.
 *   "The system tracks connection/verification status" — the status tests.
 */
class EmailAddressTest extends HelpDeskTestCase
{
    use RefreshDatabase;

    /** The Help Desk's first inbox, with its generated address. */
    private function inbox(Workspace $workspace, $owner): HelpDeskInbox
    {
        $this->helpDesk($workspace, $owner);

        return $this->firstInbox($workspace);
    }

    /** Connect an address the way the page does. */
    private function connect(Workspace $workspace, $actor, HelpDeskInbox $inbox, string $address): HelpDeskEmailAddress
    {
        $this->actingAs($actor->fresh())
            ->postJson(route('help-desk.inboxes.addresses.store', $inbox), ['address' => $address])
            ->assertOk();

        return $workspace->run(fn () => HelpDeskEmailAddress::query()->where('address', $address)->firstOrFail());
    }

    // ---- the page (§4, §5) -------------------------------------------------------------------

    public function test_the_new_email_address_screen_is_a_page_with_its_own_url(): void
    {
        [$owner, $workspace] = $this->owner();
        $inbox = $this->inbox($workspace, $owner);

        $bootstrap = $this->actingAs($owner->fresh())
            ->get(route('help-desk.inboxes.addresses.create', $inbox))
            ->assertOk()
            ->viewData('bootstrap');

        // Reached by URL alone — no modal to open first, nothing to click, which is what makes
        // it refreshable, bookmarkable and reachable with the Back button (§4).
        $this->assertSame($inbox->id, $bootstrap['inbox_id']);

        $offered = collect($bootstrap['inboxes'])->firstWhere('id', $inbox->id);

        // What somebody has to leave this page with: the address to forward to, and how (§5, §6).
        $this->assertSame($inbox->inbound_address, $offered['inbound_address']);
        $this->assertStringContainsString($inbox->inbound_address, $offered['instructions']);
    }

    public function test_the_page_offers_the_other_inboxes_of_this_help_desk_only(): void
    {
        [$ownerA, $workspaceA] = $this->owner('acme-inc');
        [$ownerB, $workspaceB] = $this->owner('globex');
        $inbox = $this->inbox($workspaceA, $ownerA);
        $this->inbox($workspaceB, $ownerB);

        $this->actingAs($ownerA->fresh())->postJson(route('help-desk.inboxes.store'), ['name' => 'Billing'])->assertOk();

        $names = collect($this->actingAs($ownerA->fresh())
            ->get(route('help-desk.inboxes.addresses.create', $inbox))
            ->assertOk()->viewData('bootstrap')['inboxes'])->pluck('name');

        $this->assertEqualsCanonicalizing([config('help-desk.default_inbox'), 'Billing'], $names->all());
    }

    public function test_another_workspaces_inbox_is_not_reachable(): void
    {
        [$ownerA, $workspaceA] = $this->owner('acme-inc');
        [$ownerB, $workspaceB] = $this->owner('globex');
        $this->inbox($workspaceA, $ownerA);
        $theirs = $this->inbox($workspaceB, $ownerB);

        $this->actingAs($ownerA->fresh())
            ->get(route('help-desk.inboxes.addresses.create', $theirs))
            ->assertNotFound();
    }

    public function test_an_agent_can_neither_see_nor_connect_addresses(): void
    {
        [$owner, $workspace] = $this->owner();
        $inbox = $this->inbox($workspace, $owner);
        $agent = $this->member($workspace, 'member', 'agent@example.com');
        $this->addToHelpDesk($workspace, $owner, $agent, HelpDeskMember::ROLE_AGENT);

        // §13: the missing button is a convenience; the refusal is the guard.
        $this->actingAs($agent->fresh())->get(route('help-desk.inboxes.addresses', $inbox))->assertForbidden();
        $this->actingAs($agent->fresh())->get(route('help-desk.inboxes.addresses.create', $inbox))->assertForbidden();
        $this->actingAs($agent->fresh())
            ->postJson(route('help-desk.inboxes.addresses.store', $inbox), ['address' => 'mine@acme.example'])
            ->assertForbidden();
    }

    // ---- saving (§9) -------------------------------------------------------------------------

    public function test_saving_returns_to_the_list_with_a_success_notification(): void
    {
        [$owner, $workspace] = $this->owner();
        $inbox = $this->inbox($workspace, $owner);

        $this->actingAs($owner->fresh())
            ->postJson(route('help-desk.inboxes.addresses.store', $inbox), ['address' => 'Support@Acme.com'])
            ->assertOk()
            ->assertJsonPath('redirect', route('help-desk.inboxes.addresses', $inbox));

        $this->assertTrue(session()->has('status'));

        $address = $workspace->run(fn () => HelpDeskEmailAddress::query()->firstOrFail());

        // Case is not part of an address: normalized once, so the stored value, the duplicate
        // check and the match on arrival all agree.
        $this->assertSame('support@acme.com', $address->address);
        $this->assertSame(HelpDeskEmailAddress::STATUS_SETUP_REQUIRED, $address->status);
    }

    public function test_the_picker_can_send_the_address_to_another_inbox_of_this_help_desk(): void
    {
        [$owner, $workspace] = $this->owner();
        $inbox = $this->inbox($workspace, $owner);

        $this->actingAs($owner->fresh())->postJson(route('help-desk.inboxes.store'), ['name' => 'Billing'])->assertOk();
        $billing = $workspace->run(fn () => HelpDeskInbox::query()->where('name', 'Billing')->firstOrFail());

        $this->actingAs($owner->fresh())
            ->postJson(route('help-desk.inboxes.addresses.store', $inbox), [
                'address' => 'billing@acme.com', 'help_desk_inbox_id' => $billing->id,
            ])
            ->assertOk()
            ->assertJsonPath('redirect', route('help-desk.inboxes.addresses', $billing));

        $address = $workspace->run(fn () => HelpDeskEmailAddress::query()->firstOrFail());
        $this->assertSame($billing->id, $address->help_desk_inbox_id);
    }

    public function test_an_inbox_from_another_help_desk_falls_back_to_the_one_in_the_url(): void
    {
        [$ownerA, $workspaceA] = $this->owner('acme-inc');
        [$ownerB, $workspaceB] = $this->owner('globex');
        $mine = $this->inbox($workspaceA, $ownerA);
        $theirs = $this->inbox($workspaceB, $ownerB);

        $this->actingAs($ownerA->fresh())
            ->postJson(route('help-desk.inboxes.addresses.store', $mine), [
                'address' => 'support@acme.com', 'help_desk_inbox_id' => $theirs->id,
            ])
            ->assertOk();

        $address = $workspaceA->run(fn () => HelpDeskEmailAddress::query()->firstOrFail());

        // A crafted id must never place one workspace's address in another's inbox.
        $this->assertSame($mine->id, $address->help_desk_inbox_id);
        $this->assertSame(0, $workspaceB->run(fn () => HelpDeskEmailAddress::query()->count()));
    }

    public function test_the_same_address_cannot_be_connected_twice(): void
    {
        [$owner, $workspace] = $this->owner();
        $inbox = $this->inbox($workspace, $owner);
        $this->connect($workspace, $owner, $inbox, 'support@acme.com');

        $this->actingAs($owner->fresh())
            ->postJson(route('help-desk.inboxes.addresses.store', $inbox), ['address' => 'support@acme.com'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('address');
    }

    public function test_two_workspaces_may_connect_the_same_customer_address(): void
    {
        [$ownerA, $workspaceA] = $this->owner('acme-inc');
        [$ownerB, $workspaceB] = $this->owner('globex');

        $this->connect($workspaceA, $ownerA, $this->inbox($workspaceA, $ownerA), 'info@agency.example');

        // Not a conflict: this column routes nothing. The GENERATED address is what routes, and
        // that one is unique everywhere.
        $this->connect($workspaceB, $ownerB, $this->inbox($workspaceB, $ownerB), 'info@agency.example');

        $this->assertSame(1, $workspaceA->run(fn () => HelpDeskEmailAddress::query()->count()));
        $this->assertSame(1, $workspaceB->run(fn () => HelpDeskEmailAddress::query()->count()));
    }

    public function test_a_removed_address_can_be_connected_again(): void
    {
        [$owner, $workspace] = $this->owner();
        $inbox = $this->inbox($workspace, $owner);
        $address = $this->connect($workspace, $owner, $inbox, 'support@acme.com');

        $this->actingAs($owner->fresh())
            ->deleteJson(route('help-desk.inboxes.addresses.destroy', ['inbox' => $inbox, 'emailAddress' => $address]))
            ->assertOk();

        $again = $this->connect($workspace, $owner, $inbox, 'support@acme.com');

        // The same row restored, not a second one: one address must not have two statuses.
        $this->assertSame($address->id, $again->id);
        $this->assertSame(1, $workspace->run(fn () => HelpDeskEmailAddress::query()->count()));
        $this->assertSame(HelpDeskEmailAddress::STATUS_SETUP_REQUIRED, $again->status);
    }

    public function test_an_address_that_is_not_an_address_is_refused(): void
    {
        [$owner, $workspace] = $this->owner();
        $inbox = $this->inbox($workspace, $owner);

        $this->actingAs($owner->fresh())
            ->postJson(route('help-desk.inboxes.addresses.store', $inbox), ['address' => 'not-an-address'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('address');
    }

    // ---- the list, statuses and verification (§7, §8, §10) -----------------------------------

    public function test_the_list_shows_each_address_with_its_status_and_forwarding_target(): void
    {
        [$owner, $workspace] = $this->owner();
        $inbox = $this->inbox($workspace, $owner);
        $this->connect($workspace, $owner, $inbox, 'support@acme.com');

        $bootstrap = $this->actingAs($owner->fresh())
            ->get(route('help-desk.inboxes.addresses', $inbox))
            ->assertOk()->viewData('bootstrap');

        $row = $bootstrap['addresses'][0];

        $this->assertSame('support@acme.com', $row['address']);
        $this->assertSame($inbox->inbound_address, $row['inbound_address']);
        $this->assertSame('Setup required', $row['status_label']);
        $this->assertNull($row['last_email_at']);
        // The instructions name the actual mailbox, so they can be pasted to whoever
        // administers the customer's mail (§6).
        $this->assertStringContainsString('support@acme.com', $row['instructions']);
        $this->assertStringContainsString($inbox->inbound_address, $row['instructions']);
    }

    public function test_verify_starts_watching_rather_than_sending_anything(): void
    {
        [$owner, $workspace] = $this->owner();
        $inbox = $this->inbox($workspace, $owner);
        $address = $this->connect($workspace, $owner, $inbox, 'support@acme.com');

        $this->actingAs($owner->fresh())
            ->postJson(route('help-desk.inboxes.addresses.verify', ['inbox' => $inbox, 'emailAddress' => $address]))
            ->assertOk()
            ->assertJsonPath('addresses.0.status', HelpDeskEmailAddress::STATUS_WAITING);

        $fresh = $workspace->run(fn () => HelpDeskEmailAddress::find($address->id));

        // Nothing was sent, and nothing could have been: the test message has to travel through
        // the customer's own mail provider.
        $this->assertNotNull($fresh->verification_started_at);
        $this->assertNull($fresh->verified_at);
    }

    public function test_a_verification_that_never_arrives_becomes_an_error_when_somebody_looks(): void
    {
        [$owner, $workspace] = $this->owner();
        $inbox = $this->inbox($workspace, $owner);
        $address = $this->connect($workspace, $owner, $inbox, 'support@acme.com');

        $workspace->run(fn () => $address->forceFill([
            'status' => HelpDeskEmailAddress::STATUS_WAITING,
            'verification_started_at' => now()->subHours((int) config('help-desk.inbound.verification_window_hours') + 1),
        ])->save());

        $rows = $this->actingAs($owner->fresh())
            ->get(route('help-desk.inboxes.addresses', $inbox))
            ->assertOk()->viewData('bootstrap')['addresses'];

        // Computed on read, with no scheduled sweep behind it: "waiting for email" three days
        // later is not waiting, it is broken.
        $this->assertSame(HelpDeskEmailAddress::STATUS_ERROR, $rows[0]['status']);
    }

    public function test_an_address_can_be_disabled_and_re_enabled(): void
    {
        [$owner, $workspace] = $this->owner();
        $inbox = $this->inbox($workspace, $owner);
        $address = $this->connect($workspace, $owner, $inbox, 'support@acme.com');
        $url = route('help-desk.inboxes.addresses.update', ['inbox' => $inbox, 'emailAddress' => $address]);

        $this->actingAs($owner->fresh())->patchJson($url, ['disabled' => true])
            ->assertOk()->assertJsonPath('addresses.0.status', HelpDeskEmailAddress::STATUS_DISABLED);

        // Back to the start rather than to whatever it was: the forwarding may have been removed
        // while it was off, and only mail can say otherwise.
        $this->actingAs($owner->fresh())->patchJson($url, ['disabled' => false])
            ->assertOk()->assertJsonPath('addresses.0.status', HelpDeskEmailAddress::STATUS_SETUP_REQUIRED);
    }

    public function test_a_disabled_address_cannot_be_verified(): void
    {
        [$owner, $workspace] = $this->owner();
        $inbox = $this->inbox($workspace, $owner);
        $address = $this->connect($workspace, $owner, $inbox, 'support@acme.com');

        $this->actingAs($owner->fresh())->patchJson(
            route('help-desk.inboxes.addresses.update', ['inbox' => $inbox, 'emailAddress' => $address]),
            ['disabled' => true],
        )->assertOk();

        $this->actingAs($owner->fresh())
            ->postJson(route('help-desk.inboxes.addresses.verify', ['inbox' => $inbox, 'emailAddress' => $address]))
            ->assertStatus(422);
    }

    public function test_an_address_belonging_to_another_inbox_is_not_addressable_through_this_one(): void
    {
        [$owner, $workspace] = $this->owner();
        $inbox = $this->inbox($workspace, $owner);
        $address = $this->connect($workspace, $owner, $inbox, 'support@acme.com');

        $this->actingAs($owner->fresh())->postJson(route('help-desk.inboxes.store'), ['name' => 'Billing'])->assertOk();
        $billing = $workspace->run(fn () => HelpDeskInbox::query()->where('name', 'Billing')->firstOrFail());

        $this->actingAs($owner->fresh())
            ->deleteJson(route('help-desk.inboxes.addresses.destroy', ['inbox' => $billing, 'emailAddress' => $address]))
            ->assertNotFound();
    }

    // ---- what actually proves a connection (§7) -----------------------------------------------

    public function test_forwarded_mail_is_what_marks_an_address_connected(): void
    {
        [$owner, $workspace] = $this->owner();
        $inbox = $this->inbox($workspace, $owner);
        $address = $this->connect($workspace, $owner, $inbox, 'support@acme.com');

        /*
         * A forwarded message, shaped the way one arrives: the customer's own address is still
         * in the To header — that is what makes the forwarding visible — while the address it
         * was delivered to is the generated one.
         */
        app(InboundEmailIngestor::class)->ingest([
            'delivered_to' => [$inbox->inbound_address],
            'to' => ['support@acme.com'],
            'from' => ['email' => 'dana@example.com', 'name' => 'Dana'],
            'subject' => 'Is anyone there?',
            'text' => 'Testing our new support address.',
            'message_id' => '<forwarded-1@mail.example>',
        ]);

        $fresh = $workspace->run(fn () => HelpDeskEmailAddress::find($address->id));

        $this->assertSame(HelpDeskEmailAddress::STATUS_CONNECTED, $fresh->status);
        $this->assertNotNull($fresh->verified_at);
        $this->assertNotNull($fresh->last_email_at);

        // Once, on the transition — every message after this is traffic, and a stream that
        // records traffic is a stream nobody reads.
        $this->assertSame(1, $workspace->run(fn () => HelpDeskActivity::query()
            ->where('event', HelpDeskActivity::EVENT_EMAIL_ADDRESS_CONNECTED)->count()));
    }

    public function test_mail_that_never_mentions_the_connected_address_leaves_it_alone(): void
    {
        [$owner, $workspace] = $this->owner();
        $inbox = $this->inbox($workspace, $owner);
        $address = $this->connect($workspace, $owner, $inbox, 'support@acme.com');

        // Sent straight to the generated address. It proves that address routes; it proves
        // nothing about a forwarding rule that was never involved.
        app(InboundEmailIngestor::class)->ingest([
            'to' => [$inbox->inbound_address],
            'from' => ['email' => 'dana@example.com'],
            'subject' => 'Direct',
            'message_id' => '<direct-1@mail.example>',
        ]);

        $this->assertSame(
            HelpDeskEmailAddress::STATUS_SETUP_REQUIRED,
            $workspace->run(fn () => HelpDeskEmailAddress::find($address->id))->status,
        );
    }

    public function test_a_disabled_address_stays_disabled_when_mail_still_arrives(): void
    {
        [$owner, $workspace] = $this->owner();
        $inbox = $this->inbox($workspace, $owner);
        $address = $this->connect($workspace, $owner, $inbox, 'support@acme.com');

        $this->actingAs($owner->fresh())->patchJson(
            route('help-desk.inboxes.addresses.update', ['inbox' => $inbox, 'emailAddress' => $address]),
            ['disabled' => true],
        )->assertOk();

        app(InboundEmailIngestor::class)->ingest([
            'delivered_to' => [$inbox->inbound_address],
            'to' => ['support@acme.com'],
            'from' => ['email' => 'dana@example.com'],
            'subject' => 'Still forwarding',
            'message_id' => '<still-1@mail.example>',
        ]);

        $fresh = $workspace->run(fn () => HelpDeskEmailAddress::find($address->id));

        // Somebody switched it off on purpose, and mail still arriving is exactly what the
        // screen told them would happen — only their mail provider can stop that.
        $this->assertSame(HelpDeskEmailAddress::STATUS_DISABLED, $fresh->status);
        $this->assertNotNull($fresh->last_email_at);
    }
}
