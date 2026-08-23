<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The Back Office security-verification code (docs/features/backoffice-auth.md, §3).
 *
 * Its own Mailable rather than a parameter on `LoginCodeMail`, for one reason that matters: the
 * subject and the body have to say BACK OFFICE. A platform-administration code arriving under
 * the customer application's wording is exactly the message somebody skims, assumes is the app
 * they were already signing into, and types into whatever asked for it.
 */
class BackofficeCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $code,
        public readonly int $ttlMinutes,
    ) {}

    public function envelope(): Envelope
    {
        // The requirement's own subject line, verbatim.
        return new Envelope(subject: 'Your Back Office verification code');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.backoffice-code');
    }
}
