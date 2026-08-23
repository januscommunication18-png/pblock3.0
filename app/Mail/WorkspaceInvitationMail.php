<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "You've been invited to join {Workspace}" (invite spec §15–§17).
 *
 * Queued per CLAUDE.md §11 — sending must not sit inside the invite request. Everything it
 * needs is passed as a scalar rather than as an Eloquent model: a queued mailable is
 * serialized and rebuilt by a worker that has no tenancy context, and re-resolving a
 * tenant-scoped invitation there would come back empty.
 *
 * `$acceptUrl` embeds the raw invitation token, which exists nowhere else (only its hash is
 * stored), so this object must never be logged.
 */
class WorkspaceInvitationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $workspaceName,
        public readonly string $inviterName,
        public readonly string $invitedEmail,
        public readonly string $roleLabel,
        public readonly string $acceptUrl,
        public readonly string $expiresOn,
        public readonly ?string $workspaceLogoUrl = null,
        /*
         * What the person is being invited TO, when it is something more specific than the
         * workspace — a Help Center Space, today (P10). All four are optional and the template
         * falls back to the plain workspace wording when they are absent, so the ordinary
         * Settings → Members invitation is unchanged by their existence.
         */
        public readonly ?string $contextTitle = null,
        public readonly ?string $contextLine = null,
        public readonly ?string $contextLabel = null,
        public readonly ?string $contextValue = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->contextTitle !== null
                ? "{$this->contextTitle} on Project Block"
                : "You've been invited to join {$this->workspaceName} on Project Block",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.workspace-invitation');
    }
}
