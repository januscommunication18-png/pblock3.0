<?php

namespace App\Mail;

use App\Models\HelpCenterRequest;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * "We have your request" — the acknowledgement a customer gets back (P27).
 *
 * Sent once, when an email opens a NEW Request. It tells them it arrived, gives them the Ticket
 * Number to quote, repeats their own subject back so they can tell which of their emails this
 * answers, and says a person will follow up.
 *
 * The point is the silence it removes: without it, a customer who emails support has no way to
 * tell the difference between "received, queued, being read" and "went nowhere".
 */
class HelpCenterTicketConfirmationMail extends Mailable
{
    use SerializesModels;

    public function __construct(
        public readonly HelpCenterRequest $request,
        /*
         * NOT named `$replyTo` / `$inReplyTo`: `Mailable` already declares a `$replyTo` property
         * and redeclaring it readonly is a fatal error. These are our inputs; the envelope and
         * headers below are where they become the mail's own.
         */
        /** The customer-facing address they wrote to — where their reply should go back. */
        private readonly ?string $supportAddress = null,
        /** The Message-ID of their email, so this lands in the same thread they started. */
        private readonly ?string $threadMessageId = null,
        /*
         * The Space's Auto-Response template, already rendered (P48).
         *
         * Passed in for the same reason the agent reply's is: the preview and the test email show
         * these exact two strings, and a Mailable that resolved its own template would be a
         * second answer to what this email says.
         *
         * Null keeps the pre-P48 wording, which is what makes this safe to send even if a Space's
         * template row is somehow unreadable.
         */
        private readonly ?string $renderedSubject = null,
        private readonly ?string $renderedHtml = null,
        /*
         * The Space's own sender (P65) — `eBay Support <inbox-…@inbound…>`.
         *
         * Null falls back to the application's global sender, which is what this mail did before
         * P65. Kept as a path rather than removed, because a Mailable that cannot be constructed
         * without a Space is a Mailable that cannot be used in a test; the caller resolves the
         * real one and passes it.
         */
        private readonly ?string $fromEmail = null,
        private readonly ?string $fromName = null,
        private readonly ?string $replyToName = null,
    ) {}

    public function envelope(): Envelope
    {
        $subject = trim((string) $this->request->subject);

        /*
         * "Re:" their subject, with the Ticket Number appended.
         *
         * Both halves earn their place. The `Re:` and their own words are what make this
         * recognisable in a crowded inbox and what threads it under the message they sent; the
         * `[#000007]` is what they can quote back, and what a future reply carries in its
         * subject line if their client strips the References header.
         *
         * A subject we prefix `Re:` twice reads as a mail loop even when it is not, so an
         * existing one is left alone.
         */
        $subject = $subject === '' ? 'Your support request' : $subject;
        $subject = preg_match('/^re:/i', $subject) === 1 ? $subject : 'Re: '.$subject;
        $subject .= ' ['.$this->request->ticketNumber().']';

        // The Space's own subject line wins when it has one (P48).
        $subject = trim((string) $this->renderedSubject) ?: $subject;

        return new Envelope(
            subject: $subject,
            /*
             * FROM and REPLY-TO are both the Space's inbound address, under the Space's display
             * name (P65). Every customer-facing email from a Space carries one identity, and the
             * acknowledgement is the FIRST one a customer ever sees — it was the odd one out
             * until P65, still going out under the application's global sender while the agent's
             * reply came from the team.
             *
             * The address is the inbound one rather than the customer-facing
             * `support@theircompany.com`, for the reason P64 records: a reply to it reaches a
             * mailbox we own and route, with nobody else's forwarding rule in between.
             */
            from: $this->fromEmail !== null
                ? new Address($this->fromEmail, $this->fromName ?? '')
                : null,
            replyTo: $this->supportAddress !== null
                ? [new Address($this->supportAddress, $this->replyToName ?? $this->fromName ?? '')]
                : [],
        );
    }

    public function headers(): Headers
    {
        /*
         * Threading, and a promise not to start a loop.
         *
         * `In-Reply-To`/`References` put this under their original message in their client.
         * `Auto-Submitted: auto-replied` is RFC 3834's way of telling the OTHER side's
         * autoresponder not to answer this — the same courtesy we extend by refusing to
         * acknowledge anything marked auto-submitted ourselves.
         */
        $headers = ['Auto-Submitted' => 'auto-replied', 'X-Auto-Response-Suppress' => 'All'];

        if ($this->threadMessageId !== null) {
            $headers['In-Reply-To'] = $this->threadMessageId;
            $headers['References'] = $this->threadMessageId;
        }

        return new Headers(text: $headers);
    }

    public function content(): Content
    {
        if ($this->renderedHtml !== null) {
            return new Content(view: 'emails.ticket-template', with: ['html' => $this->renderedHtml]);
        }

        return new Content(view: 'emails.ticket-confirmation', with: [
            'ticket' => $this->request->ticketNumber(),
            'subject' => trim((string) $this->request->subject) ?: '(no subject)',
            'customer' => $this->request->customer_name ?: null,
            'spaceName' => $this->request->space?->name,
            'receivedAt' => $this->request->last_message_at,
        ]);
    }
}
