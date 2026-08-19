<?php

namespace Tests\Feature\HelpDesk;

use App\Jobs\IngestInboundEmail;
use App\Models\HelpDeskConversation;
use App\Models\HelpDeskEmailAddress;
use App\Models\HelpDeskInbox;
use App\Models\HelpDeskMessage;
use App\Models\HelpDeskMessageAttachment;
use App\Models\Workspace;
use App\Services\HelpDesk\InboundEmailIngestor;
use App\Services\HelpDesk\PostmarkInboundPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/**
 * Postmark Inbound (Inbound Email requirements §3, §11, §12).
 *
 * The acceptance criteria this file covers:
 *   "Phase 1 uses Postmark Inbound" and "Incoming Postmark emails are routed to the correct
 *   Inbox" — the routing tests, which route on the address the message was DELIVERED to rather
 *   than the one in its To header, because that is what forwarding does to a message.
 *   "Attachments and email content are associated with the resulting Help Desk conversation" —
 *   the attachment tests.
 */
class PostmarkInboundTest extends HelpDeskTestCase
{
    use RefreshDatabase;

    private const TOKEN = 'postmark-inbound-token';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('help-desk.inbound.postmark_token', self::TOKEN);
        // Attachments are written to a disk, so the suite gets a fake one rather than the
        // project's storage directory.
        Storage::fake('local');
    }

    /** The Help Desk's first inbox, and the address Postmark would deliver to. */
    private function inbox(Workspace $workspace, $owner): HelpDeskInbox
    {
        $this->helpDesk($workspace, $owner);

        return $this->firstInbox($workspace);
    }

    /**
     * A webhook body shaped the way Postmark posts one.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function webhook(HelpDeskInbox $inbox, array $overrides = []): array
    {
        return array_merge([
            'FromFull' => ['Email' => 'Dana@Example.com', 'Name' => 'Dana Customer'],
            'From' => 'Dana Customer <Dana@Example.com>',
            // What a forwarded message looks like: the customer's own address in To, the
            // generated one as the recipient it was actually delivered to.
            'ToFull' => [['Email' => 'support@acme.com', 'Name' => 'Acme Support']],
            'To' => 'Acme Support <support@acme.com>',
            'OriginalRecipient' => $inbox->inbound_address,
            'Subject' => 'My order has not arrived',
            'TextBody' => 'It has been two weeks.',
            'HtmlBody' => '<p>It has been two weeks.</p>',
            'Date' => 'Mon, 29 Sep 2026 10:00:00 +0000',
            'Headers' => [
                ['Name' => 'Message-ID', 'Value' => '<postmark-1@mail.example>'],
            ],
            'Attachments' => [],
        ], $overrides);
    }

    /** @param array<string, mixed> $body */
    private function deliver(array $body, string $token = self::TOKEN): TestResponse
    {
        return $this->postJson("/help-desk/email/inbound/postmark/{$token}", $body);
    }

    /** @param array<string, mixed> $body */
    private function ingest(array $body): ?HelpDeskConversation
    {
        return app(InboundEmailIngestor::class)->ingest(app(PostmarkInboundPayload::class)->normalize($body));
    }

    // ---- the endpoint (§3) --------------------------------------------------------------------

    public function test_a_message_with_the_right_token_is_accepted_and_queued(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->owner();
        $inbox = $this->inbox($workspace, $owner);

        // 202, not 200: the work happens on the queue, because a webhook that answers slowly is
        // a webhook that gets called twice.
        $this->deliver($this->webhook($inbox))->assertStatus(202);

        Queue::assertPushed(IngestInboundEmail::class);
    }

    public function test_a_wrong_token_is_refused(): void
    {
        Queue::fake();
        [$owner, $workspace] = $this->owner();
        $inbox = $this->inbox($workspace, $owner);

        $this->deliver($this->webhook($inbox), 'not-the-token')->assertStatus(401);

        Queue::assertNothingPushed();
    }

    public function test_the_endpoint_is_off_when_no_token_is_configured(): void
    {
        Queue::fake();
        config()->set('help-desk.inbound.postmark_token', null);
        [$owner, $workspace] = $this->owner();
        $inbox = $this->inbox($workspace, $owner);

        // "Not configured" must never mean "accepts anything".
        $this->deliver($this->webhook($inbox))->assertStatus(503);

        Queue::assertNothingPushed();
    }

    public function test_a_message_addressed_to_nobody_is_refused(): void
    {
        Queue::fake();

        $this->deliver(['FromFull' => ['Email' => 'dana@example.com'], 'Subject' => 'Hello'])
            ->assertStatus(422);

        Queue::assertNothingPushed();
    }

    // ---- routing and mapping (§11) ------------------------------------------------------------

    public function test_a_forwarded_message_is_routed_by_the_address_it_was_delivered_to(): void
    {
        [$owner, $workspace] = $this->owner();
        $inbox = $this->inbox($workspace, $owner);

        $conversation = $this->ingest($this->webhook($inbox));

        /*
         * The case the whole feature exists for. The To header says support@acme.com — an
         * address this application does not own and cannot route by — and only
         * `OriginalRecipient` carries the generated address. A lookup that read To alone would
         * drop every forwarded message.
         */
        $this->assertNotNull($conversation);
        $this->assertSame($inbox->id, $conversation->help_desk_inbox_id);
        $this->assertSame('My order has not arrived', $conversation->subject);
        $this->assertSame('dana@example.com', $conversation->customer_email);
        $this->assertSame('Dana Customer', $conversation->customer_name);
    }

    public function test_the_message_keeps_the_sender_recipients_and_both_bodies(): void
    {
        [$owner, $workspace] = $this->owner();
        $inbox = $this->inbox($workspace, $owner);

        $conversation = $this->ingest($this->webhook($inbox, [
            'CcFull' => [['Email' => 'colleague@acme.com', 'Name' => 'A Colleague']],
        ]));

        $message = $workspace->run(fn () => HelpDeskMessage::query()
            ->where('help_desk_conversation_id', $conversation->id)->firstOrFail());

        $this->assertSame('dana@example.com', $message->from_email);
        $this->assertSame(['support@acme.com'], $message->to);
        $this->assertSame(['colleague@acme.com'], $message->cc);
        $this->assertSame('It has been two weeks.', $message->body_text);
        $this->assertStringContainsString('two weeks', (string) $message->body_html);
        // Threading identifiers come from the headers, which is where a mail client puts them.
        $this->assertSame('postmark-1@mail.example', $message->message_id);
    }

    public function test_a_reply_threads_by_the_headers_postmark_forwards(): void
    {
        [$owner, $workspace] = $this->owner();
        $inbox = $this->inbox($workspace, $owner);

        $first = $this->ingest($this->webhook($inbox));

        $reply = $this->ingest($this->webhook($inbox, [
            'Subject' => 'Re: My order has not arrived',
            'Headers' => [
                ['Name' => 'Message-ID', 'Value' => '<postmark-2@mail.example>'],
                ['Name' => 'In-Reply-To', 'Value' => '<postmark-1@mail.example>'],
                ['Name' => 'References', 'Value' => '<postmark-1@mail.example>'],
            ],
        ]));

        $this->assertSame($first->id, $reply->id);
        $this->assertSame(1, $workspace->run(fn () => HelpDeskConversation::query()->count()));
    }

    public function test_the_same_webhook_delivered_twice_changes_nothing(): void
    {
        [$owner, $workspace] = $this->owner();
        $inbox = $this->inbox($workspace, $owner);

        $this->ingest($this->webhook($inbox, ['Attachments' => [$this->attachment()]]));
        $this->ingest($this->webhook($inbox, ['Attachments' => [$this->attachment()]]));

        // At-least-once delivery is what every provider offers. One message, one file.
        $this->assertSame(1, $workspace->run(fn () => HelpDeskMessage::query()->count()));
        $this->assertSame(1, $workspace->run(fn () => HelpDeskMessageAttachment::query()->count()));
    }

    public function test_a_forwarded_message_marks_the_connected_address_connected(): void
    {
        [$owner, $workspace] = $this->owner();
        $inbox = $this->inbox($workspace, $owner);

        $this->actingAs($owner->fresh())
            ->postJson(route('help-desk.inboxes.addresses.store', $inbox), ['address' => 'support@acme.com'])
            ->assertOk();

        $this->ingest($this->webhook($inbox));

        $address = $workspace->run(fn () => HelpDeskEmailAddress::query()->firstOrFail());

        // §7's only honest proof: a message arrived carrying the customer's address, so the
        // forwarding rule in their mail provider works.
        $this->assertSame(HelpDeskEmailAddress::STATUS_CONNECTED, $address->status);
    }

    // ---- attachments (§11.6, §12) ---------------------------------------------------------------

    /** @return array<string, mixed> */
    private function attachment(string $name = 'receipt.pdf', string $content = 'a receipt'): array
    {
        return [
            'Name' => $name,
            'ContentType' => 'application/pdf',
            'Content' => base64_encode($content),
            'ContentLength' => strlen($content),
            'ContentID' => '',
        ];
    }

    public function test_attachments_are_stored_against_the_conversations_message(): void
    {
        [$owner, $workspace] = $this->owner();
        $inbox = $this->inbox($workspace, $owner);

        $conversation = $this->ingest($this->webhook($inbox, [
            'Attachments' => [$this->attachment(), $this->attachment('screenshot.png', 'an image')],
        ]));

        $attachments = $workspace->run(fn () => HelpDeskMessageAttachment::query()->orderBy('id')->get());

        $this->assertCount(2, $attachments);
        $this->assertSame('receipt.pdf', $attachments[0]->name);
        $this->assertSame(strlen('a receipt'), $attachments[0]->size);
        $this->assertNull($attachments[0]->skipped_reason);

        // Stored, and reachable from the conversation the customer sent them to.
        Storage::disk('local')->assertExists($attachments[0]->path);
        $message = $workspace->run(fn () => HelpDeskMessage::query()
            ->where('help_desk_conversation_id', $conversation->id)->firstOrFail());
        $this->assertSame($message->id, $attachments[0]->help_desk_message_id);

        // The path is random rather than derived: §13 asks for non-guessable storage references.
        $this->assertStringNotContainsString('receipt', $attachments[0]->path);
    }

    public function test_a_file_over_the_limit_is_recorded_without_being_stored(): void
    {
        [$owner, $workspace] = $this->owner();
        $inbox = $this->inbox($workspace, $owner);
        config()->set('help-desk.attachments.max_file_bytes', 8);

        $this->ingest($this->webhook($inbox, [
            'Attachments' => [$this->attachment('huge.mov', 'far too many bytes')],
        ]));

        $attachment = $workspace->run(fn () => HelpDeskMessageAttachment::query()->firstOrFail());

        /*
         * The row exists without a file, deliberately. An agent needs to know the customer sent
         * something: a message that quietly loses a 40 MB video reads as a customer who sent
         * nothing, which is how "I already sent you the screenshot" becomes an argument.
         */
        $this->assertNull($attachment->path);
        $this->assertFalse($attachment->isStored());
        $this->assertStringContainsString('Too large', (string) $attachment->skipped_reason);
    }

    public function test_the_message_survives_an_attachment_that_cannot_be_read(): void
    {
        [$owner, $workspace] = $this->owner();
        $inbox = $this->inbox($workspace, $owner);

        $conversation = $this->ingest($this->webhook($inbox, [
            'Attachments' => [['Name' => 'broken.bin', 'Content' => 'not base64 !!', 'ContentType' => 'application/octet-stream']],
        ]));

        // Losing the whole conversation over one unreadable file would be the worse failure.
        $this->assertNotNull($conversation);
        $this->assertSame(1, $workspace->run(fn () => HelpDeskMessage::query()->count()));
        $this->assertNotNull($workspace->run(fn () => HelpDeskMessageAttachment::query()->firstOrFail())->skipped_reason);
    }
}
