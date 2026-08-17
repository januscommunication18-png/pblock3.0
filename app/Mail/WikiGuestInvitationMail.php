<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "{Inviter} shared {Collection} with you" (docs/features/wiki-external-guests.md).
 *
 * Queued per CLAUDE.md §11. Everything is a scalar rather than an Eloquent model: a queued
 * mailable is rebuilt by a worker with no tenancy context, and re-resolving a tenant-scoped guest
 * there would come back empty.
 *
 * `$openUrl` embeds the raw token, which exists nowhere else — only its hash is stored — so this
 * object must never be logged.
 */
class WikiGuestInvitationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $guestName,
        public readonly string $workspaceName,
        public readonly string $inviterName,
        public readonly string $collectionName,
        public readonly string $openUrl,
        public readonly ?string $workspaceLogoUrl = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "{$this->inviterName} shared “{$this->collectionName}” with you",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.wiki-guest-invitation');
    }
}
