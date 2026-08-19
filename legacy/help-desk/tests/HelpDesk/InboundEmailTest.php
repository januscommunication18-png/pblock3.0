<?php

namespace Tests\Feature\HelpDesk;

use App\Jobs\IngestInboundEmail;
use App\Models\HelpDeskConversation;
use App\Models\HelpDeskInbox;
use App\Models\HelpDeskMember;
use App\Models\HelpDeskMessage;
use App\Models\Workspace;
use App\Services\HelpDesk\InboundEmailIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

/**
 * Inbound email — ingestion, threading and numbering
 * (docs/features/help-desk.md — Phase 2, slice 2: FR-2.4, FR-2.7; decision H19).
 *
 * The phase's acceptance criteria, one test each:
 *   "Inbound email creates exactly one conversation when no thread exists"
 *   "Replies attach to the correct conversation using thread identifiers"
 * plus the edge cases §9 names: duplicate delivery, job retries, and tenant isolation on the
 * one endpoint the outside world can reach.
 */
class InboundEmailTest extends HelpDeskTestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-inbound-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('help-desk.inbound.secret', self::SECRET);
    }

    /** An inbox that receives at a real address. */
    private function receivingInbox(Workspace $workspace, string $address, ?int $assigneeId = null): HelpDeskInbox
    {
        $inbox = $this->firstInbox($workspace);

        return $workspace->run(function () use ($inbox, $address, $assigneeId) {
            $inbox->forceFill(['inbound_address' => $address, 'default_assignee_id' => $assigneeId])->save();

            return $inbox->fresh();
        });
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function email(array $overrides = []): array
    {
        return array_merge([
            'to' => ['support@acme.example'],
            'from' => ['email' => 'customer@example.com', 'name' => 'Dana Customer'],
            'subject' => 'My order has not arrived',
            'text' => 'It has been two weeks.',
            'html' => '<p>It has been two weeks.</p>',
            'message_id' => '<first@mail.example>',
            'received_at' => '2026-09-29T10:00:00+00:00',
        ], $overrides);
    }

    /**
     * POST a payload the way a mail provider would: a raw JSON body, signed with the shared
     * secret. Not `postJson`, because the signature is over the EXACT bytes sent.
     *
     * @param  array<string, mixed>  $payload
     */
    private function deliver(array $payload, ?string $secret = self::SECRET): TestResponse
    {
        $body = json_encode($payload);
        $headers = ['Content-Type' => 'application/json'];

        if ($secret !== null) {
            $headers['X-PB-Signature'] = hash_hmac('sha256', $body, $secret);
        }

        return $this->call('POST', '/help-desk/email/inbound', [], [], [], $this->transformHeadersToServerVars($headers), $body);
    }

    /** @param array<string, mixed> $payload */
    private function ingest(array $payload): ?HelpDeskConversation
    {
        return app(InboundEmailIngestor::class)->ingest($payload);
    }

    // ---- the endpoint --------------------------------------------------------------------

    public function test_a_signed_message_is_accepted_and_queued(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->owner();
        $this->receivingInbox($workspace, 'support@acme.example');

        // 202, not 200: a webhook that answers slowly is a webhook that gets called twice, so
        // the work happens on the queue (CLAUDE.md §11).
        $this->deliver($this->email())->assertStatus(202);

        Queue::assertPushed(IngestInboundEmail::class);
    }

    public function test_an_unsigned_or_wrongly_signed_message_is_refused(): void
    {
        Queue::fake();

        $this->deliver($this->email(), secret: null)->assertStatus(401);
        $this->deliver($this->email(), secret: 'not-the-secret')->assertStatus(401);

        Queue::assertNothingPushed();
    }

    public function test_the_endpoint_is_off_when_no_secret_is_configured(): void
    {
        Queue::fake();
        config()->set('help-desk.inbound.secret', null);

        // "Not configured" must never mean "accepts anything".
        $this->deliver($this->email())->assertStatus(503);

        Queue::assertNothingPushed();
    }

    public function test_a_message_with_no_recipient_is_refused(): void
    {
        Queue::fake();

        $this->deliver(['from' => ['email' => 'customer@example.com']])->assertStatus(422);

        Queue::assertNothingPushed();
    }

    // ---- creating a conversation (acceptance criterion 1) ----------------------------------

    public function test_a_first_email_creates_exactly_one_conversation(): void
    {
        [$owner, $workspace] = $this->owner();
        $this->receivingInbox($workspace, 'support@acme.example');

        $conversation = $this->ingest($this->email());

        $this->assertNotNull($conversation);
        $this->assertSame(1, $workspace->run(fn () => HelpDeskConversation::query()->count()));
        $this->assertSame('My order has not arrived', $conversation->subject);
        $this->assertSame('customer@example.com', $conversation->customer_email);
        $this->assertSame('Dana Customer', $conversation->customer_name);
        $this->assertSame(HelpDeskConversation::STATUS_OPEN, $conversation->status);
        $this->assertSame(1, $conversation->number);
    }

    public function test_the_inbox_default_assignee_is_applied(): void
    {
        [$owner, $workspace] = $this->owner();
        $agent = $this->member($workspace, 'member', 'agent@example.com');
        $membership = $this->addToHelpDesk($workspace, $owner, $agent, HelpDeskMember::ROLE_AGENT);
        $this->receivingInbox($workspace, 'support@acme.example', $membership->id);

        $conversation = $this->ingest($this->email());

        $this->assertSame($membership->id, $conversation->assignee_id);
    }

    public function test_numbers_run_in_sequence_and_never_repeat(): void
    {
        [$owner, $workspace] = $this->owner();
        $this->receivingInbox($workspace, 'support@acme.example');

        $numbers = [];
        foreach (['a', 'b', 'c'] as $id) {
            $numbers[] = $this->ingest($this->email(['message_id' => "<{$id}@mail.example>"]))->number;
        }

        $this->assertSame([1, 2, 3], $numbers);
    }

    public function test_html_is_sanitized_on_the_way_in(): void
    {
        [$owner, $workspace] = $this->owner();
        $this->receivingInbox($workspace, 'support@acme.example');

        $conversation = $this->ingest($this->email([
            'html' => '<p>Hello<script>alert(1)</script><img src=x onerror="alert(2)"></p>',
        ]));

        $body = $workspace->run(fn () => HelpDeskMessage::query()
            ->where('help_desk_conversation_id', $conversation->id)->value('body_html'));

        // Customer mail is the most hostile HTML this application will ever store (§13).
        $this->assertStringNotContainsString('<script', $body);
        $this->assertStringNotContainsString('onerror', $body);
        $this->assertStringContainsString('Hello', $body);
    }

    // ---- threading (acceptance criterion 2) ------------------------------------------------

    public function test_a_reply_attaches_to_the_conversation_by_in_reply_to(): void
    {
        [$owner, $workspace] = $this->owner();
        $this->receivingInbox($workspace, 'support@acme.example');

        $first = $this->ingest($this->email());
        $reply = $this->ingest($this->email([
            'message_id' => '<second@mail.example>',
            'in_reply_to' => '<first@mail.example>',
            'subject' => 'Re: My order has not arrived',
        ]));

        $this->assertSame($first->id, $reply->id);
        $this->assertSame(1, $workspace->run(fn () => HelpDeskConversation::query()->count()));
        $this->assertSame(2, $workspace->run(fn () => HelpDeskMessage::query()->count()));
    }

    public function test_a_reply_attaches_by_the_references_chain_when_in_reply_to_is_missing(): void
    {
        [$owner, $workspace] = $this->owner();
        $this->receivingInbox($workspace, 'support@acme.example');

        $first = $this->ingest($this->email());

        // Some clients drop In-Reply-To but keep the ancestry in References.
        $reply = $this->ingest($this->email([
            'message_id' => '<third@mail.example>',
            'references' => ['<unknown@elsewhere.example>', '<first@mail.example>'],
        ]));

        $this->assertSame($first->id, $reply->id);
    }

    public function test_an_unrelated_email_with_the_same_subject_is_a_new_conversation(): void
    {
        [$owner, $workspace] = $this->owner();
        $this->receivingInbox($workspace, 'support@acme.example');

        $first = $this->ingest($this->email());
        $other = $this->ingest($this->email([
            'message_id' => '<other@mail.example>',
            'from' => ['email' => 'someone-else@example.com', 'name' => 'Someone Else'],
            'subject' => 'Re: My order has not arrived',
        ]));

        // "Re: Invoice" from two customers is two cases; a heuristic that merges them is worse
        // than a new conversation somebody moves.
        $this->assertNotSame($first->id, $other->id);
        $this->assertSame(2, $workspace->run(fn () => HelpDeskConversation::query()->count()));
    }

    // ---- duplicates and retries (§9) --------------------------------------------------------

    public function test_the_same_message_delivered_twice_is_stored_once(): void
    {
        [$owner, $workspace] = $this->owner();
        $this->receivingInbox($workspace, 'support@acme.example');

        $first = $this->ingest($this->email());
        $again = $this->ingest($this->email());

        // At-least-once delivery is what every mail provider offers; anything less than
        // idempotent here means duplicate cases in front of an agent.
        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, $workspace->run(fn () => HelpDeskConversation::query()->count()));
        $this->assertSame(1, $workspace->run(fn () => HelpDeskMessage::query()->count()));
    }

    public function test_a_retried_job_does_not_duplicate_anything(): void
    {
        [$owner, $workspace] = $this->owner();
        $this->receivingInbox($workspace, 'support@acme.example');

        $payload = $this->email();

        // What a failed-then-retried queue job does: run the whole handler again.
        (new IngestInboundEmail($payload))->handle(app(InboundEmailIngestor::class));
        (new IngestInboundEmail($payload))->handle(app(InboundEmailIngestor::class));

        $this->assertSame(1, $workspace->run(fn () => HelpDeskMessage::query()->count()));
    }

    // ---- routing and isolation ---------------------------------------------------------------

    public function test_mail_to_an_address_nobody_owns_is_dropped_quietly(): void
    {
        [$owner, $workspace] = $this->owner();
        $this->receivingInbox($workspace, 'support@acme.example');

        $result = $this->ingest($this->email(['to' => ['nobody@nowhere.example']]));

        // Mail arrives at addresses nobody configured all the time; raising here would make the
        // queue retry each of them until it gave up.
        $this->assertNull($result);
        $this->assertSame(0, $workspace->run(fn () => HelpDeskConversation::query()->count()));
    }

    public function test_a_message_lands_only_in_the_workspace_that_owns_the_address(): void
    {
        [$ownerA, $workspaceA] = $this->owner('acme-inc');
        [$ownerB, $workspaceB] = $this->owner('globex');
        $this->receivingInbox($workspaceA, 'support@acme.example');
        $this->receivingInbox($workspaceB, 'support@globex.example');

        $this->ingest($this->email(['to' => ['support@globex.example']]));

        $this->assertSame(0, $workspaceA->run(fn () => HelpDeskConversation::query()->count()));
        $this->assertSame(1, $workspaceB->run(fn () => HelpDeskConversation::query()->count()));
    }

    public function test_the_address_match_ignores_case(): void
    {
        [$owner, $workspace] = $this->owner();
        $this->receivingInbox($workspace, 'support@acme.example');

        $conversation = $this->ingest($this->email(['to' => ['Support@Acme.Example']]));

        // Mail servers do not agree about case, and a customer's client may not preserve it.
        $this->assertNotNull($conversation);
    }

    public function test_a_message_addressed_to_several_places_finds_the_inbox_among_them(): void
    {
        [$owner, $workspace] = $this->owner();
        $this->receivingInbox($workspace, 'support@acme.example');

        $conversation = $this->ingest($this->email([
            'to' => ['someone@example.com', ['email' => 'support@acme.example', 'name' => 'Acme Support']],
        ]));

        $this->assertNotNull($conversation);
        $this->assertSame(1, $conversation->number);
    }
}
