<?php

namespace App\Services\HelpCenter\Inbound;

use App\Mail\HelpCenterTicketConfirmationMail;
use App\Models\HelpCenterEmailAddress;
use App\Models\HelpCenterEmailTemplate;
use App\Models\HelpCenterInbox;
use App\Models\HelpCenterRequest;
use App\Services\HelpCenter\EmailTemplateRenderer;
use App\Services\HelpCenter\SpaceSender;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Acknowledging a customer's email (docs/features/help-center.md, P27).
 *
 * The rules for WHETHER to reply live here rather than in the Mailable or the job, because they
 * are the whole substance of this feature: an auto-reply that goes out at the wrong moment is
 * worse than no auto-reply at all — it answers machines, it doubles up on threads, and it tells
 * spammers that a human address is live.
 */
class TicketConfirmer
{
    public function __construct(
        private readonly EmailTemplateRenderer $templates,
        private readonly SpaceSender $sender,
    ) {}

    /**
     * Send the acknowledgement, if this Request has earned one.
     *
     * Called AFTER the ingest transaction has committed, so the Ticket Number in the email is a
     * number that exists.
     */
    public function confirm(HelpCenterRequest $request, HelpCenterInbox $inbox, PostmarkPayload $payload): void
    {
        $reason = $this->refusal($request, $payload);

        if ($reason !== null) {
            Log::info('help-center.confirmation.skipped', [
                'request_id' => $request->id,
                'reason' => $reason,
            ]);

            return;
        }

        $support = $this->replyToAddress($inbox);

        try {
            /*
             * Rendered from the SPACE'S OWN template (P48).
             *
             * Composed here rather than inside the Mailable so that the preview and the test
             * email in Settings can produce the same two strings from the same call — a preview
             * built by different code from the send is a preview of nothing.
             */
            $space = $request->space;

            $composed = $space === null ? null : $this->templates->compose(
                $space,
                HelpCenterEmailTemplate::TYPE_AUTO_RESPONSE,
                $this->templates->variables($request, $space, null, null, $support),
            );

            /*
             * The SPACE'S sender, on the first email a customer ever gets from us (P65).
             *
             * Until P65 this went out under the application's global From while the agent's
             * reply came from the team — so the acknowledgement and the answer to it looked like
             * two different organisations, and the acknowledgement is the one that arrives first.
             *
             * `forSpace()` rather than `for()`: the Request exists, but the Inbox is the one that
             * actually received this mail, and passing it is more honest than letting the
             * resolver pick the Space's first.
             */
            $identity = $this->sender->forSpace($space, $inbox);

            Mail::to($request->customer_email)->send(new HelpCenterTicketConfirmationMail(
                $request,
                $support,
                $payload->messageId(),
                $composed['subject'] ?? null,
                $composed['html'] ?? null,
                $identity['from_email'],
                $identity['from_name'],
                $identity['reply_to_name'],
            ));

            Log::info('help-center.confirmation.sent', [
                'request_id' => $request->id,
                'ticket' => $request->ticketNumber(),
                'to' => $request->customer_email,
            ]);
        } catch (Throwable $e) {
            /*
             * Swallowed, like the assignment notification's.
             *
             * The Request is committed. Letting this bubble would fail the ingest job, and its
             * retry would re-run an ingest that has nothing left to do — the acknowledgement is
             * a courtesy, and losing one is not worth risking the record of the email itself.
             */
            Log::error('help-center.confirmation.failed', [
                'request_id' => $request->id,
                'to' => $request->customer_email,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Why we are NOT replying, or null to go ahead.
     *
     * A string rather than a boolean so the log says which rule fired — "no confirmation was
     * sent" is a support question, and the answer should not require reading this file.
     */
    private function refusal(HelpCenterRequest $request, PostmarkPayload $payload): ?string
    {
        /*
         * ONCE PER TICKET, on the message that opened it.
         *
         * A customer adding "one more thing" to an existing thread does not need to be told
         * again that we have their request — they are looking at the ticket number in the
         * subject line as they type.
         */
        if (! $request->wasRecentlyCreated) {
            return 'not_a_new_request';
        }

        // Answering spam confirms a live human address to whoever sent it.
        if ($request->is_spam || $payload->isSpam()) {
            return 'spam';
        }

        // Autoresponders, mailing lists and bounce daemons (see PostmarkPayload).
        if ($payload->isAutoSubmitted()) {
            return 'auto_submitted';
        }

        /*
         * The Space switched this template off (P48).
         *
         * The requirement's own words: "If disabled, a customer can still create a ticket, but
         * the automatic acknowledgment email will not be sent." Checked here with the other
         * refusals rather than at the send, so the reason lands in the same log line as every
         * other reason this email did not go out — which is the question this method exists to
         * make answerable.
         */
        $space = $request->space;

        if ($space !== null
            && ! $this->templates->resolve($space, HelpCenterEmailTemplate::TYPE_AUTO_RESPONSE)['enabled']) {
            return 'template_disabled';
        }

        $to = mb_strtolower(trim((string) $request->customer_email));

        if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return 'no_valid_sender';
        }

        /*
         * Addresses that exist to not be written to.
         *
         * `mailer-daemon` and `postmaster` are bounce machinery: replying to them is how one
         * failed delivery becomes a conversation between two mail servers. `no-reply` has said
         * plainly that nobody reads it.
         */
        $localPart = mb_strstr($to, '@', true) ?: $to;

        foreach (['mailer-daemon', 'postmaster', 'no-reply', 'noreply', 'donotreply', 'do-not-reply'] as $blocked) {
            if (str_contains($localPart, $blocked)) {
                return 'unreplyable_sender';
            }
        }

        return null;
    }

    /**
     * Where the customer's reply should go — the address they already write to.
     *
     * A VERIFIED address first: verified means mail forwarded from it has actually reached us,
     * so it is the one address on the Inbox we know completes the round trip. Falling back to
     * the first configured one is better than falling back to nothing, and nothing is better
     * than a guess — with no address at all, Reply-To is omitted and replies go to the sender,
     * which is a mailbox somebody at least reads.
     */
    private function replyToAddress(HelpCenterInbox $inbox): ?string
    {
        $addresses = $inbox->relationLoaded('emailAddresses')
            ? $inbox->emailAddresses
            : $inbox->emailAddresses()->get();

        $verified = $addresses->firstWhere('status', HelpCenterEmailAddress::STATUS_VERIFIED);

        return ($verified ?? $addresses->first())?->email;
    }
}
