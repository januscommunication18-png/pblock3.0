<?php

namespace App\Services\HelpCenter\Inbound;

use App\Models\HelpCenterEmailAddress;
use App\Models\HelpCenterInbox;
use App\Models\HelpCenterMessage;
use App\Models\HelpCenterRequest;
use App\Models\HelpCenterRequestActivity;
use App\Models\HelpCenterStatus;
use App\Services\HelpCenter\Metadata\MetadataMapper;
use App\Services\HelpCenter\Metadata\TicketMetadata;
use App\Services\HelpCenter\RequestActivity;
use App\Services\HelpCenter\SnoozeManager;
use App\Services\HelpCenter\TicketBroadcaster;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Turning a parsed inbound email into a REQUEST (docs/features/help-center.md, P6/P9).
 *
 * "Every inbound email creates a Request with a unique Ticket Number" (P9) — this is where that
 * happens. Three things, in one transaction: the message finds or opens its Request, the message
 * is stored, and the customer-facing address it was forwarded from is marked
 * Verified — which is what finally makes §11's "Verified" status reachable, and closes out
 * HC-D7's deferral.
 *
 * IDEMPOTENT by construction. Postmark retries any webhook that does not answer 2xx, so the
 * same Message-ID can arrive several times; the unique key on `(tenant_id, message_id)` and the
 * check below mean a retry is a no-op rather than a duplicate reply in somebody's thread.
 */
class InboundIngestor
{
    public function __construct(
        private readonly AutoAssigner $assigner,
        private readonly CustomerMatcher $customers,
        private readonly RequestActivity $activity,
        private readonly SnoozeManager $snooze,
        // Reads the `+tag` off our own inbound addresses (P62).
        private readonly InboundRouter $router,
        // Files that came with the email (P66).
        private readonly InboundAttachmentStore $attachments,
        // Tells the Space's open Inboxes that something moved (P67).
        private readonly TicketBroadcaster $broadcaster,
        // Keeps the customer's new words and drops the thread under them (P68).
        private readonly QuotedReplyStripper $stripper,
        // Runs the Space's Company & Customer mapping over what arrived (P75).
        private readonly MetadataMapper $mapper,
    ) {}

    /**
     * The body as it should be STORED, and the body as it ARRIVED (P68).
     *
     * The raw copy is kept for troubleshooting and is never rendered — the requirement is
     * explicit that it must not appear in the ticket conversation. It is stored only when the
     * cleaning actually changed something, so an ordinary first email does not carry two
     * identical copies of itself.
     *
     * A low-confidence parse stores the body UNCHANGED and says so in the log. The requirement's
     * rule is that an uncertain parser must not discard the message, and "logged for
     * investigation… not exposed to the customer or support agent" — so nothing about it reaches
     * the screen.
     *
     * @return array{text: ?string, html: ?string, raw_text: ?string, raw_html: ?string, confident: bool}
     */
    private function clean(PostmarkPayload $payload): array
    {
        $text = $this->stripper->text($payload->textBody());
        $html = $this->stripper->html($payload->htmlBody());

        $confident = $text['confident'] && $html['confident'];
        $changed = $text['clean'] !== (string) $payload->textBody()
            || $html['clean'] !== (string) $payload->htmlBody();

        if (! $confident) {
            Log::warning('help-center.inbound.quote_strip_uncertain', [
                'message_id' => $payload->messageId(),
                'text_confident' => $text['confident'],
                'html_confident' => $html['confident'],
            ]);
        }

        return [
            // Empty stays NULL, as it was before: '' and null render differently, and a message
            // with no text body has always been the second of those.
            'text' => $text['clean'] === '' ? null : $text['clean'],
            'html' => $html['clean'] === '' ? null : $html['clean'],
            'raw_text' => $changed ? $payload->textBody() : null,
            'raw_html' => $changed ? $payload->htmlBody() : null,
            'confident' => $confident,
        ];
    }

    /** Store one message. Returns the Request it landed in, or null if it was a duplicate. */
    public function ingest(HelpCenterInbox $inbox, PostmarkPayload $payload): ?HelpCenterRequest
    {
        $tenantId = $inbox->tenant_id;
        $messageId = $payload->messageId();

        // Seen already — Postmark retrying, or the same mail reaching two of our addresses.
        if ($messageId !== null && $this->alreadyStored($tenantId, $messageId)) {
            Log::info('help-center.inbound.duplicate', [
                'inbox_id' => $inbox->id,
                'message_id' => $messageId,
            ]);

            return null;
        }

        return DB::transaction(function () use ($inbox, $payload, $tenantId, $messageId) {
            $request = $this->requestFor($inbox, $payload, $tenantId);

            /*
             * The first two rows of a new Request's timeline (P36).
             *
             * Null actor throughout: an email opened this and a rule assigned it, and a history
             * that credits a person for either is a history that misattributes every ticket
             * nobody touched. Only for a NEW Request — a reply lands in one that already has
             * these rows.
             */
            /*
             * Company & Customer mapping (P75 §5), for a new Request AND for a reply.
             *
             * Inside the ingest transaction and not a queued job of its own: the requirement's
             * flow puts "Link Customer and Company to Ticket" before the ticket is done, and a
             * second job would mean the Inbox drawing a ticket whose panel is empty until it
             * lands. It is also the reason a reply runs it — a customer who has since been given
             * an External ID, or whose company was created last week, should be linked on their
             * next message rather than only on their first.
             *
             * Cheap when the Space has the feature off: `apply()` stores the parsed metadata and
             * returns.
             */
            // A Space is not optional for a routed Inbox, but the relation is nullable and an
            // ingest must not die on a shape it can simply skip.
            if ($inbox->space !== null) {
                $this->mapper->apply($request, $inbox->space, TicketMetadata::fromEmail($payload));
            }

            if ($request->wasRecentlyCreated) {
                $this->activity->created($request, 'inbound_email');

                if ($request->assignee_id !== null) {
                    $this->activity->record(
                        $request,
                        HelpCenterRequestActivity::EVENT_ASSIGNED,
                        'assignee',
                        null,
                        $request->assignee?->displayName(),
                        ['via' => 'default_assignee'],
                    );
                }
            }

            $clean = $this->clean($payload);

            $message = HelpCenterMessage::create([
                'tenant_id' => $tenantId,
                'help_center_request_id' => $request->id,
                'direction' => HelpCenterMessage::DIRECTION_INBOUND,
                'from_email' => $payload->fromEmail(),
                'from_name' => $payload->fromName(),
                // Kept whole for reply handling (§20 rule 10).
                'to_recipients' => $payload->toRecipients(),
                'cc_recipients' => $payload->ccRecipients(),
                'subject' => $payload->subject(),
                /*
                 * The customer's NEW words, with the quoted thread removed (P68).
                 *
                 * `$clean` is computed above the create() so the raw originals go into the same
                 * INSERT — there is never a moment when the only stored copy of what arrived is
                 * the cleaned one.
                 */
                'body_text' => $clean['text'],
                'body_html' => $clean['html'],
                'raw_text' => $clean['raw_text'],
                'raw_html' => $clean['raw_html'],
                'quote_stripped' => $clean['confident'],
                'message_id' => $messageId,
                'provider_message_id' => $payload->providerMessageId(),
                /*
                 * OURS, not the sender's (P70).
                 *
                 * `receivedAt()` is the moment of ingest; `sentAt()` is what the customer's mail
                 * client claimed. Every clock and the timeline's order run on the first, because
                 * a header a stranger wrote had this ticket waiting longer than it existed and
                 * sorted a reply above the message it answered.
                 */
                'received_at' => $payload->receivedAt(),
                'sent_at' => $payload->sentAt(),
            ]);

            /*
             * The files that came with it (P66).
             *
             * AFTER the message row, because an attachment belongs to a message and needs its
             * id for the storage path. Inside the transaction with it, so a ticket is never
             * committed holding half of what the customer sent — an ingest that fails leaves
             * orphaned blobs on the disk, which are recoverable, rather than a message that
             * silently lost an invoice, which is not.
             *
             * SPAM IS INCLUDED, deliberately. The message itself is already stored for audit
             * (see below); keeping its words and discarding its files would make that audit
             * partial, and a ticket wrongly marked spam must be restorable whole.
             */
            $this->attachments->store($message, $payload);

            /*
             * SPAM TAKES NO FURTHER ACTION (P47).
             *
             * The message above is already stored, and that is deliberate — the requirement asks
             * that customer messages be kept for audit. What must not happen is everything
             * below: reopening the ticket, restarting its waiting clock, waking its snooze.
             *
             * Without this, one reply to a spam ticket undid the marking entirely. `closed_at`
             * was cleared and `waiting_since` reset to now, which put a ticket somebody had
             * explicitly thrown away at the TOP of an Inbox sorted by longest wait — the one
             * place it must never be. And spam is exactly the category of mail that replies to
             * itself, so the ticket would come back every time.
             *
             * `last_message_at` is left alone with the rest: it orders queues, and spam does not
             * belong in the ordering of queues it is not in.
             */
            if ($request->is_spam) {
                $this->markAddressesVerified($inbox, $payload);

                /*
                 * Spam updates the screen but NEVER toasts (P67).
                 *
                 * The message was stored for audit, so a Spam view that is open should show it
                 * — but interrupting an agent for mail they have already thrown away is exactly
                 * the notification that teaches people to ignore notifications.
                 */
                $this->broadcaster->updated($request);

                return $request;
            }

            /*
             * A reply reopens its Request AND hands the wait back to the agent.
             *
             * A customer answering a closed thread is not opening a new problem; they are
             * continuing the old one, and leaving it closed would hide their reply from every
             * view except Closed. The clock matters just as much: a Request sitting in "Waiting
             * on Customer" has stopped being the customer's to answer the moment they answer it,
             * and an Inbox that kept their clock running would rank the one message that needs a
             * reply as the one nobody is waiting on (P9, Waiting Period).
             */
            $request->forceFill([
                'last_message_at' => $payload->receivedAt(),
                'last_activity_at' => $payload->receivedAt(),
                'closed_at' => null,
                'waiting_since' => $payload->receivedAt(),
            ])->save();

            /*
             * A reply may also END A SNOOZE (P45).
             *
             * Only when the agent chose "If no reply"; `customerReplied()` holds that rule, and
             * a snooze set "Regardless of reply" is left alone here — the message above has
             * already been stored either way, which is the requirement's "the new customer reply
             * should still be stored on the ticket and visible when the ticket is reopened".
             *
             * After the forceFill, not before: `isSnoozed()` reads the row, and waking a Request
             * whose closed_at is about to be cleared in the same breath would test the wrong
             * state.
             *
             * The actor is NULL — a customer is not a user of this application, and the timeline
             * says "System brought this ticket back — the customer replied" rather than crediting
             * whoever last touched it.
             */
            $this->snooze->customerReplied($request);

            /*
             * A reply reopens the ticket, so any UNSENT rating request is withdrawn (P56 §6).
             *
             * The customer is still talking; asking them how it went would be asking about a
             * conversation they are in the middle of.
             */
            app(\App\Services\HelpCenter\RatingManager::class)->cancelPending($request);

            $this->markAddressesVerified($inbox, $payload);

            /*
             * Tell the Space's open Inboxes (P67).
             *
             * LAST, and after every write above, so the payload describes the ticket as it now
             * is. The event itself is `ShouldDispatchAfterCommit`, so the socket message cannot
             * beat this transaction's COMMIT and have a browser refetch the state from before
             * it — which on a fast connection is the ordinary case, not a rare race.
             *
             * A NEW Request is `ticket.created`; anything else is a reply on one that already
             * existed, which is the event the requirement writes out in full.
             */
            if ($request->wasRecentlyCreated) {
                $this->broadcaster->created($request);
            } else {
                $this->broadcaster->replyReceived($request, $message);
            }

            return $request;
        });
    }

    /**
     * The thread this message belongs to — found by reply headers, or newly opened.
     *
     * Matching on `In-Reply-To`/`References` rather than on the subject line: "Re: Invoice"
     * from two different customers is two problems, and a subject match would merge them.
     */
    private function requestFor(
        HelpCenterInbox $inbox,
        PostmarkPayload $payload,
        string $tenantId,
    ): HelpCenterRequest {
        /*
         * THE TICKET-TAGGED ADDRESS FIRST (P62).
         *
         * `inbox-8pb4kxdj+r42-9f1c3a7e02@…` names the ticket in the one part of the email a
         * customer's client cannot rewrite. Threading headers are tried after it, not before:
         * they are the polite method and the less reliable one — Outlook rewrites References,
         * some mobile clients drop them, and a forwarded mail loses them entirely.
         *
         * The tag is signed, so a guessed address matches nothing (see
         * HelpCenterRequest::fromReplyTag). A tag that IS valid but names a Request in another
         * Inbox is ignored too — the `help_center_inbox_id` check below is what stops a leaked
         * address being used to post into somebody else's queue.
         */
        foreach ($payload->routableAddresses() as $address) {
            $tag = $this->router->replyTag($address);

            if ($tag === null) {
                continue;
            }

            $id = HelpCenterRequest::fromReplyTag($tag);

            if ($id === null) {
                continue;
            }

            $tagged = HelpCenterRequest::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('help_center_inbox_id', $inbox->id)
                ->find($id);

            if ($tagged !== null) {
                return $tagged;
            }

            Log::info('help-center.inbound.reply_tag_unmatched', [
                'inbox_id' => $inbox->id,
                'tag' => $tag,
            ]);
        }

        $replyIds = $payload->replyToIds();

        if ($replyIds !== []) {
            $existing = HelpCenterRequest::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('help_center_inbox_id', $inbox->id)
                ->whereIn('thread_key', $replyIds)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            // Not the root, but a message we already hold — join through its Request.
            $sibling = HelpCenterMessage::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereIn('message_id', $replyIds)
                ->first();

            if ($sibling !== null) {
                return $sibling->conversation;
            }
        }

        $default = $this->defaultStatus($inbox);

        /*
         * Auto-assignment from the opening status's Default assignees (P26).
         *
         * Here, in the CREATE, rather than as a second write afterwards: a Request that exists
         * Unassigned for a moment and then changes owner is a row two screens can disagree about,
         * and it would put a spurious entry in whatever activity history this grows later.
         *
         * Never for spam. A message the filter already doubts should not page somebody, and
         * assigning it would drop it into their Mine queue where the spam view exists precisely
         * so it does not have to be.
         */
        $assigneeId = $default !== null && ! $payload->isSpam()
            ? $this->assigner->pick($default, (int) $inbox->help_center_space_id)
            : null;

        /*
         * The sender, matched to a customer or created as one (P33).
         *
         * Before the Request, so the link is written in the same insert — a Request that exists
         * for a moment with no customer is a row the panel can render without a sender block.
         */
        $customer = $this->customers->match(
            $tenantId,
            $payload->fromEmail(),
            $payload->fromName(),
            $payload->receivedAt(),
        );

        return HelpCenterRequest::create([
            'tenant_id' => $tenantId,
            'help_center_customer_id' => $customer?->id,
            'help_center_space_id' => $inbox->help_center_space_id,
            'help_center_inbox_id' => $inbox->id,
            /*
             * Opens in the WORKFLOW'S OWN starting status (P9, Default Status).
             *
             * Not a hard-coded "New": the requirement is explicit that ProjectBlock keeps no
             * fixed Help Center status list, so a Space whose workflow starts at "Triage" opens
             * its Requests in Triage without a line of code knowing that word exists.
             */
            'help_center_status_id' => $default?->id,
            'assignee_id' => $assigneeId,
            'ticket_number' => HelpCenterRequest::nextTicketNumber($tenantId),
            'subject' => $payload->subject(),
            'preview' => $this->preview($payload),
            'customer_email' => $payload->fromEmail(),
            'customer_name' => $payload->fromName(),
            // The root of the thread, so later replies can find it.
            'thread_key' => $payload->messageId(),
            'last_message_at' => $payload->receivedAt(),
            'last_activity_at' => $payload->receivedAt(),
            // The clock starts on arrival, unless the starting status says nobody is waiting.
            'waiting_since' => $default?->waiting_on === HelpCenterStatus::WAITING_NEITHER
                ? null
                : $payload->receivedAt(),
            'is_spam' => $payload->isSpam(),
        ]);
    }

    /**
     * The Space workflow's starting status.
     *
     * `withoutGlobalScopes` because this runs from the ingest job, and while a workspace context
     * IS established by then, the Inbox is the thing that established it — reading the workflow
     * through the Inbox's own space id keeps this correct even if that ever stops being true.
     */
    private function defaultStatus(HelpCenterInbox $inbox): ?HelpCenterStatus
    {
        $statuses = HelpCenterStatus::query()
            ->withoutGlobalScopes()
            ->where('help_center_space_id', $inbox->help_center_space_id)
            ->ordered()
            ->get();

        return $statuses->firstWhere('is_default', true)
            ?? $statuses->firstWhere('system_key', HelpCenterStatus::SYSTEM_OPEN)
            ?? $statuses->first();
    }

    /**
     * The first ~200 characters of the message, for the Inbox list (P9, Subject / Preview).
     *
     * Text body first and HTML stripped only as a fallback: the text part is what the sender
     * wrote, while a stripped HTML part carries whatever whitespace and hidden preheader text
     * their mail client injected. Whitespace is collapsed because a preview rendered in one grid
     * line should not be mostly the newlines of a quoted signature.
     */
    private function preview(PostmarkPayload $payload): ?string
    {
        $body = trim((string) $payload->textBody());

        if ($body === '') {
            $body = trim(html_entity_decode(strip_tags((string) $payload->htmlBody()), ENT_QUOTES | ENT_HTML5));
        }

        $body = trim((string) preg_replace('/\s+/u', ' ', $body));

        if ($body === '') {
            return null;
        }

        return mb_substr($body, 0, 200).(mb_strlen($body) > 200 ? '…' : '');
    }

    /**
     * Mark the address this mail was forwarded from as Verified (§11).
     *
     * This is the moment §11 was waiting for: receiving a forwarded message IS the proof that
     * the forwarding works, so no test-email button is needed to establish it. Only addresses
     * actually present on the message are touched — an Inbox with three connected addresses
     * verifies the one that delivered, not all three.
     */
    private function markAddressesVerified(HelpCenterInbox $inbox, PostmarkPayload $payload): void
    {
        $seen = array_map(
            'mb_strtolower',
            array_merge($payload->toRecipients(), $payload->ccRecipients()),
        );

        if ($seen === []) {
            return;
        }

        HelpCenterEmailAddress::query()
            ->withoutGlobalScopes()
            ->where('help_center_inbox_id', $inbox->id)
            ->whereIn('email', $seen)
            ->where('status', '!=', HelpCenterEmailAddress::STATUS_VERIFIED)
            ->update([
                'status' => HelpCenterEmailAddress::STATUS_VERIFIED,
                'verified_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function alreadyStored(string $tenantId, string $messageId): bool
    {
        return HelpCenterMessage::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('message_id', $messageId)
            ->exists();
    }
}
