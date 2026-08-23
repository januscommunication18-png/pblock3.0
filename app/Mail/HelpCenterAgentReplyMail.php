<?php

namespace App\Mail;

use App\Models\HelpCenterMessage;
use App\Models\HelpCenterRequest;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * An agent's reply, on its way to the customer (docs/features/help-center.md, P36).
 *
 * The first outbound mail this module sends that is somebody's actual words rather than a
 * notice. Everything about it exists to keep the customer's thread intact: the subject carries
 * the ticket number, the headers reference the message being answered, and the Reply-To is the
 * address they already write to — so their answer comes back down the same path and the
 * ingestor attaches it to this Request instead of opening a second one.
 */
class HelpCenterAgentReplyMail extends Mailable
{
    use SerializesModels;

    public function __construct(
        public readonly HelpCenterRequest $request,
        public readonly HelpCenterMessage $message,
        /* NOT `$replyTo`: `Mailable` declares that property and redeclaring it is fatal (P27). */
        private readonly ?string $supportAddress = null,
        private readonly ?string $threadMessageId = null,
        /*
         * The Space's Agent Reply template, already rendered (P48).
         *
         * Passed in rather than resolved here, because the same two strings are what the preview
         * and the test email show — and a Mailable that fetched its own template would be a
         * fourth place that decides what a reply looks like.
         *
         * Null keeps the pre-P48 behaviour: the agent's words, with the subject built below.
         * That path is what a Space with a deleted template row would take if the renderer ever
         * handed back nothing, and it is better to send the reply plainly than not at all.
         */
        private readonly ?string $renderedSubject = null,
        private readonly ?string $renderedHtml = null,
        /*
         * The SPACE'S verified address, and the name the customer sees (P62).
         *
         * Null falls back to the application's global sender, which is what every reply did
         * before P62 — kept as a path rather than removed, because a Mailable that cannot be
         * constructed without a verified address is a Mailable that cannot be used in a test.
         * The controller refuses the send long before it gets here.
         */
        private readonly ?string $fromEmail = null,
        private readonly ?string $fromName = null,
        /*
         * The name on the REPLY-TO as well (P65).
         *
         * The requirement states From and Reply-To as one identity —
         * `eBay Support <inbox-…@inbound…>` twice — so a bare mailbox on the Reply-To would
         * leave a raw token showing in the clients that display it.
         */
        private readonly ?string $replyToName = null,
    ) {}

    public function envelope(): Envelope
    {
        // The template's subject when there is one (P48), otherwise the built-in shape.
        $subject = trim((string) $this->renderedSubject);

        if ($subject === '') {
            $subject = trim((string) $this->request->subject) ?: 'Your support request';
            $subject = preg_match('/^re:/i', $subject) === 1 ? $subject : 'Re: '.$subject;
            // The ticket number survives even when a client strips References (P27).
            $subject .= ' ['.$this->request->ticketNumber().']';
        }

        return new Envelope(
            subject: $subject,
            /*
             * FROM is the Space's own address (P62) — "Customer Support <support@…>".
             *
             * `Address` rather than a bare string so the display name travels: a From with no
             * name shows a customer a raw mailbox, which is the thing this requirement exists to
             * stop.
             */
            from: $this->fromEmail !== null
                ? new Address($this->fromEmail, $this->fromName ?? '')
                : null,
            /*
             * REPLY-TO is the same inbound address, under the same display name (P64, P65), so
             * the answer routes to us even when the customer's client has dropped the threading
             * headers — and shows the team's name when the client renders it.
             */
            replyTo: $this->supportAddress !== null
                ? [new Address($this->supportAddress, $this->replyToName ?? $this->fromName ?? '')]
                : [],
        );
    }

    public function headers(): Headers
    {
        $headers = [];

        /*
         * Threading, and NO auto-reply suppression.
         *
         * The confirmation (P27) sets `Auto-Submitted: auto-replied`, because it is a machine
         * talking. This is a person, and a reply the customer's client is told not to answer is
         * a conversation with one side muted.
         */
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

        return new Content(view: 'emails.agent-reply', with: [
            'ticket' => $this->request->ticketNumber(),
            /*
             * The HTML the agent wrote, already sanitized on the way in (P41), with the plain
             * text as the fallback for a message stored before the editor existed.
             */
            'bodyHtml' => $this->message->body_html,
            'body' => (string) $this->message->body_text,
            'agent' => $this->message->from_name,
            'spaceName' => $this->request->space?->name,
        ]);
    }
}
