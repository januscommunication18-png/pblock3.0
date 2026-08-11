<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "{ID} is now blocked" (Collaboration spec §58).
 *
 * Queued and scalar-only, for the same reasons as the other work item mail: adding a
 * dependency is an inline action that must return immediately, and a queue worker has no
 * tenancy context in which a tenant-scoped work item would resolve.
 */
class WorkItemBlockedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** @param array<int, array{identifier:string, title:string}> $blockers */
    public function __construct(
        public readonly string $identifier,
        public readonly string $title,
        public readonly string $projectName,
        public readonly string $actorName,
        public readonly array $blockers,
        public readonly string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->identifier} {$this->title} — now blocked");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.work-item-blocked');
    }
}
