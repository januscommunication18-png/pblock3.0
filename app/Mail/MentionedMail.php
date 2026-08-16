<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "{ACTOR} mentioned you" — sent when someone names you in a description or a comment.
 *
 * Stands in for the Inbox until it exists (mentions §19): the same information, delivered the
 * one way this application can deliver it today. §31 asks that the notification architecture
 * not be coupled to the editor, and it is not — this reads the `mentions` records, so when the
 * Inbox lands it reads the same rows and this becomes one channel of a Notification.
 *
 * Scalars only, like every other mailable here: a queued one is rebuilt by a worker with no
 * tenancy context, where a tenant-scoped model would not resolve. Sent immediately by product
 * decision — see WorkItemStatusNotifier for that trade.
 */
class MentionedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $identifier,
        public readonly string $title,
        public readonly string $projectName,
        public readonly string $actorName,
        /** 'the description' or 'a comment' — where the mention was. */
        public readonly string $where,
        /** What was actually said where you were named, already stripped of markup. */
        public readonly string $excerpt,
        public readonly string $url,
        /**
         * The work item's own description.
         *
         * Context, and only shown when it adds any: a mention IN the description would
         * otherwise print the same paragraph twice under two headings.
         */
        public readonly ?string $description = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->actorName} mentioned you on {$this->identifier} {$this->title}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.mentioned');
    }
}
