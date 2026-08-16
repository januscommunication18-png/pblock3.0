<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "{IDENTIFIER} moved to {STATE}" — sent to everyone subscribed to a work item when its state
 * changes (docs/features/work-item-toolbar.md).
 *
 * Queued (CLAUDE.md §11): a state change is an inline chip edit and must return immediately —
 * with ten subscribers, sending inline would put ten SMTP round-trips in front of the click.
 *
 * Everything is a scalar, not an Eloquent model, for the same reason WorkItemAssignedMail does
 * it: a queued mailable is rebuilt by a worker with no tenancy context, where a tenant-scoped
 * work item would not resolve.
 *
 * `$url` is the item's own page, which is the whole point of subscribing — the mail says what
 * moved and takes you straight back to it.
 */
class WorkItemStatusChangedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $identifier,
        public readonly string $title,
        public readonly string $projectName,
        public readonly string $actorName,
        public readonly ?string $fromState,
        public readonly ?string $toState,
        public readonly string $url,
    ) {}

    public function envelope(): Envelope
    {
        $to = $this->toState ?? 'No state';

        return new Envelope(subject: "{$this->identifier} {$this->title} — moved to {$to}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.work-item-status-changed');
    }
}
