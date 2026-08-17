<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "{Title} ({ID}) — updated" — sent when a work item's properties change.
 *
 * ONE email per update, listing every field that moved, rather than one per field: a single
 * edit can change status, assignee and due date together, and three emails about one action is
 * how people learn to filter this address away.
 *
 * Queued (CLAUDE.md §11), and everything is a scalar: a queued mailable is rebuilt by a worker
 * with no tenancy context, where a tenant-scoped work item would not resolve.
 */
class WorkItemUpdatedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int, array{field: string, old: string, new: string}>  $changes
     *                                                                               Already humanised by the caller — the mailable renders, it does not interpret.
     */
    public function __construct(
        public readonly string $identifier,
        public readonly string $title,
        public readonly string $projectName,
        public readonly string $updatedBy,
        public readonly string $updatedAt,
        public readonly string $url,
        public readonly array $changes,
    ) {}

    public function envelope(): Envelope
    {
        // Name first, ID in brackets — the format used everywhere a work item is named.
        return new Envelope(subject: "{$this->title} ({$this->identifier}) — updated");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.work-item-updated');
    }
}
