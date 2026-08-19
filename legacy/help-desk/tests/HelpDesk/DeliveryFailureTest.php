<?php

namespace Tests\Feature\HelpDesk;

use App\Jobs\RecordEmailDeliveryEvent;
use App\Models\HelpDeskActivity;
use App\Models\HelpDeskConversation;
use App\Models\HelpDeskEmailDelivery;
use App\Models\HelpDeskMember;
use App\Models\HelpDeskMessage;
use App\Models\Workspace;
use App\Notifications\HelpDeskDeliveryFailed;
use App\Services\HelpDesk\EmailDeliveryRecorder;
use App\Services\HelpDesk\InboundEmailIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

/**
 * Outbound delivery failures (docs/features/help-desk.md — Phase 2, slice 3: FR-2.9).
 *
 * The acceptance criterion is "failed outbound delivery creates a visible delivery event and
 * notification", and both halves are here for the same reason: a bounced reply is otherwise
 * silent — the agent believes they answered, the customer never heard, and the case sits there
 * looking handled.
 */
class DeliveryFailureTest extends HelpDeskTestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-inbound-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('help-desk.inbound.secret', self::SECRET);
        Notification::fake();
    }

    /** A conversation with an outbound reply in it — what a delivery event is about. */
    private function repliedConversation(Workspace $workspace, ?int $assigneeId = null): HelpDeskConversation
    {
        $inbox = $this->firstInbox($workspace);

        $workspace->run(fn () => $inbox->forceFill([
            'inbound_address' => 'support@acme.example',
            'default_assignee_id' => $assigneeId,
        ])->save());

        $conversation = app(InboundEmailIngestor::class)->ingest([
            'to' => ['support@acme.example'],
            'from' => ['email' => 'customer@example.com', 'name' => 'Dana Customer'],
            'subject' => 'Where is my order?',
            'text' => 'It has been two weeks.',
            'message_id' => '<inbound@mail.example>',
        ]);

        // Phase 3 sends replies; this is the row one leaves behind, which is all a delivery
        // event needs to point at.
        $workspace->run(fn () => HelpDeskMessage::create([
            'tenant_id' => $workspace->id,
            'help_desk_id' => $conversation->help_desk_id,
            'help_desk_conversation_id' => $conversation->id,
            'direction' => HelpDeskMessage::DIRECTION_OUTBOUND,
            'message_id' => 'reply@acme.example',
            'from_email' => 'support@acme.example',
            'to' => ['customer@example.com'],
            'subject' => 'Re: Where is my order?',
            'body_text' => 'It is on its way.',
            'sent_at' => now(),
        ]));

        return $conversation->fresh();
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function event(array $overrides = []): array
    {
        return array_merge([
            'message_id' => '<reply@acme.example>',
            'status' => 'bounced',
            'recipient' => 'customer@example.com',
            'reason' => '550 5.1.1 user unknown',
            'event_id' => 'evt_1',
            'occurred_at' => '2026-09-30T09:00:00+00:00',
        ], $overrides);
    }

    /** @param array<string, mixed> $payload */
    private function deliver(array $payload, ?string $secret = self::SECRET): TestResponse
    {
        $body = json_encode($payload);
        $headers = ['Content-Type' => 'application/json'];

        if ($secret !== null) {
            $headers['X-PB-Signature'] = hash_hmac('sha256', $body, $secret);
        }

        return $this->call('POST', '/help-desk/email/delivery', [], [], [], $this->transformHeadersToServerVars($headers), $body);
    }

    /** @param array<string, mixed> $payload */
    private function record(array $payload): ?HelpDeskEmailDelivery
    {
        return app(EmailDeliveryRecorder::class)->record($payload);
    }

    // ---- the endpoint --------------------------------------------------------------------

    public function test_a_signed_event_is_accepted_and_queued(): void
    {
        Queue::fake();

        $this->deliver($this->event())->assertStatus(202);

        Queue::assertPushed(RecordEmailDeliveryEvent::class);
    }

    public function test_an_unsigned_event_is_refused(): void
    {
        Queue::fake();

        $this->deliver($this->event(), secret: null)->assertStatus(401);
        $this->deliver($this->event(), secret: 'wrong')->assertStatus(401);

        Queue::assertNothingPushed();
    }

    public function test_an_event_naming_no_message_is_refused(): void
    {
        Queue::fake();

        $this->deliver(['status' => 'bounced'])->assertStatus(422);

        Queue::assertNothingPushed();
    }

    // ---- the visible event (acceptance criterion 5) ------------------------------------------

    public function test_a_bounce_is_recorded_and_marks_the_conversation(): void
    {
        [$owner, $workspace] = $this->owner();
        $conversation = $this->repliedConversation($workspace);

        $delivery = $this->record($this->event());

        $this->assertNotNull($delivery);
        $this->assertTrue($delivery->isFailure());
        $this->assertSame('550 5.1.1 user unknown', $delivery->reason);

        // Visible in every list, without asking the deliveries table per row.
        $this->assertNotNull($workspace->run(fn () => HelpDeskConversation::find($conversation->id))->delivery_failed_at);
    }

    public function test_the_failure_shows_on_the_conversation_list(): void
    {
        [$owner, $workspace] = $this->owner();
        $agent = $this->member($workspace, 'member', 'agent@example.com');
        $membership = $this->addToHelpDesk($workspace, $owner, $agent, HelpDeskMember::ROLE_AGENT, [$this->firstInbox($workspace)->id]);
        $this->repliedConversation($workspace, $membership->id);

        $this->record($this->event());

        $bootstrap = $this->actingAs($agent->fresh())->get(route('help-desk.conversations'))
            ->assertOk()->viewData('bootstrap');

        $this->assertTrue($bootstrap['conversations'][0]['delivery_failed']);
    }

    public function test_a_failure_is_written_to_the_activity_stream(): void
    {
        [$owner, $workspace] = $this->owner();
        $this->repliedConversation($workspace);

        $this->record($this->event());

        $entry = $workspace->run(fn () => HelpDeskActivity::query()
            ->where('event', HelpDeskActivity::EVENT_DELIVERY_FAILED)->firstOrFail());

        $this->assertSame(
            'could not deliver #1 to customer@example.com — 550 5.1.1 user unknown',
            $entry->sentence(),
        );
        // Nobody here did this; a mail server did.
        $this->assertNull($entry->actor_id);
    }

    public function test_a_later_success_clears_the_flag(): void
    {
        [$owner, $workspace] = $this->owner();
        $conversation = $this->repliedConversation($workspace);

        $this->record($this->event());
        $this->record($this->event(['status' => 'delivered', 'event_id' => 'evt_2', 'reason' => null]));

        // The flag means "currently broken", not "was ever broken" — the deliveries table is
        // what remembers the history.
        $this->assertNull($workspace->run(fn () => HelpDeskConversation::find($conversation->id))->delivery_failed_at);
    }

    public function test_a_deferral_is_not_treated_as_a_failure(): void
    {
        [$owner, $workspace] = $this->owner();
        $conversation = $this->repliedConversation($workspace);

        $this->record($this->event(['status' => 'deferred', 'reason' => 'greylisted, will retry']));

        // Mail servers defer constantly and it usually resolves itself; treating it as a
        // failure would train people to ignore the flag.
        $this->assertNull($workspace->run(fn () => HelpDeskConversation::find($conversation->id))->delivery_failed_at);
        Notification::assertNothingSent();
    }

    // ---- the notification (acceptance criterion 5) --------------------------------------------

    public function test_the_assignee_is_told(): void
    {
        [$owner, $workspace] = $this->owner();
        $agent = $this->member($workspace, 'member', 'agent@example.com');
        $membership = $this->addToHelpDesk($workspace, $owner, $agent, HelpDeskMember::ROLE_AGENT);
        $this->repliedConversation($workspace, $membership->id);

        $this->record($this->event());

        Notification::assertSentTo($agent, HelpDeskDeliveryFailed::class);
    }

    public function test_an_unassigned_conversation_tells_the_help_desk_admins(): void
    {
        [$owner, $workspace] = $this->owner();
        $deskAdmin = $this->member($workspace, 'member', 'desk-admin@example.com');
        $agent = $this->member($workspace, 'member', 'agent@example.com');
        $this->addToHelpDesk($workspace, $owner, $deskAdmin, HelpDeskMember::ROLE_ADMIN);
        $this->addToHelpDesk($workspace, $owner, $agent, HelpDeskMember::ROLE_AGENT);
        $this->repliedConversation($workspace);

        $this->record($this->event());

        // An unassigned conversation with a bounced reply belongs to whoever runs the desk —
        // and to nobody else, because a notification everybody receives is one nobody acts on.
        Notification::assertSentTo($deskAdmin, HelpDeskDeliveryFailed::class);
        Notification::assertNotSentTo($agent, HelpDeskDeliveryFailed::class);
    }

    public function test_a_second_bounce_on_the_same_conversation_does_not_notify_again(): void
    {
        [$owner, $workspace] = $this->owner();
        $agent = $this->member($workspace, 'member', 'agent@example.com');
        $membership = $this->addToHelpDesk($workspace, $owner, $agent, HelpDeskMember::ROLE_AGENT);
        $this->repliedConversation($workspace, $membership->id);

        $this->record($this->event());
        $this->record($this->event(['event_id' => 'evt_2', 'recipient' => 'someone-else@example.com']));

        // §8: notifications must be deduplicated. The person who was told is the person who has
        // to fix it; telling them twice does not make it more fixed.
        Notification::assertSentToTimes($agent, HelpDeskDeliveryFailed::class, 1);
        // Both events are still recorded — the alarm is deduplicated, the history is not.
        $this->assertSame(2, $workspace->run(fn () => HelpDeskEmailDelivery::query()->count()));
    }

    // ---- retries and routing -------------------------------------------------------------------

    public function test_the_same_event_delivered_twice_is_stored_once(): void
    {
        [$owner, $workspace] = $this->owner();
        $agent = $this->member($workspace, 'member', 'agent@example.com');
        $membership = $this->addToHelpDesk($workspace, $owner, $agent, HelpDeskMember::ROLE_AGENT);
        $this->repliedConversation($workspace, $membership->id);

        $payload = $this->event();
        (new RecordEmailDeliveryEvent($payload))->handle(app(EmailDeliveryRecorder::class));
        (new RecordEmailDeliveryEvent($payload))->handle(app(EmailDeliveryRecorder::class));

        $this->assertSame(1, $workspace->run(fn () => HelpDeskEmailDelivery::query()->count()));
        Notification::assertSentToTimes($agent, HelpDeskDeliveryFailed::class, 1);
    }

    public function test_an_event_about_a_message_we_never_sent_is_dropped_quietly(): void
    {
        [$owner, $workspace] = $this->owner();
        $this->repliedConversation($workspace);

        $result = $this->record($this->event(['message_id' => '<not-ours@elsewhere.example>']));

        // Providers report on everything that leaves an account, including mail this
        // application knows nothing about.
        $this->assertNull($result);
        $this->assertSame(0, $workspace->run(fn () => HelpDeskEmailDelivery::query()->count()));
    }

    public function test_an_event_lands_only_in_the_workspace_that_sent_the_message(): void
    {
        [$ownerA, $workspaceA] = $this->owner('acme-inc');
        [$ownerB, $workspaceB] = $this->owner('globex');
        $this->repliedConversation($workspaceA);

        // B has a Help Desk and inbox of its own, and none of this is about it.
        $inboxB = $this->firstInbox($workspaceB);
        $workspaceB->run(fn () => $inboxB->forceFill(['inbound_address' => 'support@globex.example'])->save());

        $this->record($this->event());

        $this->assertSame(1, $workspaceA->run(fn () => HelpDeskEmailDelivery::query()->count()));
        $this->assertSame(0, $workspaceB->run(fn () => HelpDeskEmailDelivery::query()->count()));
    }

    public function test_an_unknown_status_is_treated_as_a_failure(): void
    {
        [$owner, $workspace] = $this->owner();
        $conversation = $this->repliedConversation($workspace);

        $this->record($this->event(['status' => 'something-new-from-the-provider']));

        // Whatever it was, it was not "delivered", and the wrong side to err on here is silence.
        $this->assertNotNull($workspace->run(fn () => HelpDeskConversation::find($conversation->id))->delivery_failed_at);
    }
}
