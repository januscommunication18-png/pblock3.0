<?php

namespace App\Mail;

use App\Models\HelpCenterInboundTest;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * The probe sent at the start of an inbound test (docs/features/help-center.md, P7).
 *
 * It goes to the Inbox's own customer-facing address — NOT to our inbound address — so that it
 * travels the customer's real path: their mailbox, their forwarding rule, Postmark, our webhook.
 * Sending straight to the inbound address would prove only that Postmark works, which is the one
 * thing the requirement says must not count as a pass.
 */
class InboundTestMail extends Mailable
{
    public function __construct(public readonly HelpCenterInboundTest $test) {}

    public function envelope(): Envelope
    {
        /*
         * The token rides in the SUBJECT.
         *
         * It is the only part of a message that reliably survives a forwarding rule intact —
         * custom headers get stripped or rewritten by providers, and bodies get quoted,
         * re-wrapped, or converted between HTML and text.
         */
        return new Envelope(
            subject: $this->test->subjectTag().' ProjectBlock inbound email test',
        );
    }

    public function headers(): Headers
    {
        // A belt-and-braces copy. Checked on arrival when it survives; never relied upon.
        return new Headers(text: ['X-ProjectBlock-Test' => $this->test->test_token]);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.inbound-test', with: [
            'token' => $this->test->test_token,
            'inbound' => $this->test->inbound_email_address,
        ]);
    }
}
