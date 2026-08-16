<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "You've been assigned {IDENTIFIER}" — sent when someone becomes a work item's assignee.
 *
 * Queued (CLAUDE.md §11): assigning is an inline chip edit and must return immediately.
 * Everything is passed as a scalar, not as an Eloquent model, because a queued mailable is
 * rebuilt by a worker with no tenancy context, where a tenant-scoped work item would not
 * resolve.
 *
 * This is deliberately mail-only for now. When the Phase 1 notification stack (database +
 * broadcast + Reverb bell, CLAUDE.md §8/§9) lands, this becomes the mail channel of a
 * Notification rather than a standalone Mailable.
 */
class WorkItemAssignedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $identifier,
        public readonly string $title,
        public readonly string $projectName,
        public readonly string $assignerName,
        public readonly string $assigneeName,
        public readonly string $url,
        /**
         * What the work is, not just what it is called.
         *
         * An assignment email that carries only a title makes everybody open the app to find
         * out whether it is urgent. Plain text rather than the stored markup: mail clients
         * render HTML unevenly, and this is context, not the document.
         */
        public readonly ?string $description = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->identifier} {$this->title} — assigned to you");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.work-item-assigned');
    }
}
