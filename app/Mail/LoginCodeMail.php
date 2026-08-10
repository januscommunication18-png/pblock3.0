<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Delivers the 6-digit verification / login code (spec D-A3).
 * Sent synchronously (not queued) so codes arrive instantly; the plaintext code lives only on this in-memory object,
 * never in the DB (only its hash is stored).
 */
class LoginCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $code,
        public readonly int $ttlMinutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your Project Block verification code');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.login-code');
    }
}
