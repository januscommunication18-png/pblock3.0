<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * "How did we do?" (docs/features/help-center.md, P56).
 *
 * Takes the rendered subject and HTML rather than building them, exactly as the other two ticket
 * mailables do since P48 — the Space's own template is the authority on what this says, and a
 * Mailable that composed its own wording would be a second answer to that question.
 *
 * `Auto-Submitted: auto-replied` like the acknowledgement (P27): a machine is asking, and a
 * customer's out-of-office should not answer it.
 */
class HelpCenterRatingRequestMail extends Mailable
{
    use SerializesModels;

    public function __construct(
        private readonly string $renderedSubject,
        private readonly string $renderedHtml,
        private readonly ?string $supportAddress = null,
        /* The Space's own sender (P65). Null keeps the pre-P65 global sender. */
        private readonly ?string $fromEmail = null,
        private readonly ?string $fromName = null,
        private readonly ?string $replyToName = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: trim($this->renderedSubject) ?: 'How was your support experience?',
            /*
             * The Space's identity on both headers (P65). A rating request is a customer-facing
             * ticket email like any other, and one arriving from a different sender than the
             * conversation it is asking about reads as a third party harvesting feedback.
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
        return new Headers(text: [
            'Auto-Submitted' => 'auto-replied',
            'X-Auto-Response-Suppress' => 'All',
        ]);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.ticket-template', with: ['html' => $this->renderedHtml]);
    }
}
