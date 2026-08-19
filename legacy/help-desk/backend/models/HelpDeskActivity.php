<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * One entry in a Help Desk's activity stream (FR-1.9, §8). TENANT-SCOPED.
 *
 * Append-only by intent: written by HelpDeskActivityRecorder and never updated, so the stream
 * is a record of what happened rather than a mutable summary. `subject` and anything in `meta`
 * are resolved at WRITE time — renaming an inbox or a person later must not rewrite what the
 * history says they were called when it happened.
 */
class HelpDeskActivity extends Model
{
    use BelongsToTenant;

    protected $table = 'help_desk_activity';

    // Membership (FR-1.4/1.6/1.8)
    public const EVENT_MEMBER_ADDED = 'member_added';

    public const EVENT_MEMBER_ROLE_CHANGED = 'member_role_changed';

    public const EVENT_MEMBER_STATUS_CHANGED = 'member_status_changed';

    public const EVENT_MEMBER_INBOXES_CHANGED = 'member_inboxes_changed';

    public const EVENT_MEMBER_REMOVED = 'member_removed';

    // Invitations (FR-1.5)
    public const EVENT_MEMBER_INVITED = 'member_invited';

    public const EVENT_INVITE_REVOKED = 'invite_revoked';

    public const EVENT_INVITE_ACCEPTED = 'invite_accepted';

    // Inboxes (FR-1.7)
    public const EVENT_INBOX_CREATED = 'inbox_created';

    public const EVENT_INBOX_RENAMED = 'inbox_renamed';

    // Inbound addresses and the customer addresses forwarded to them (Inbound Email
    // requirements §2, §7, §9, §10)
    public const EVENT_INBOX_ADDRESS_REGENERATED = 'inbox_address_regenerated';

    public const EVENT_EMAIL_ADDRESS_ADDED = 'email_address_added';

    public const EVENT_EMAIL_ADDRESS_CONNECTED = 'email_address_connected';

    public const EVENT_EMAIL_ADDRESS_STATUS_CHANGED = 'email_address_status_changed';

    public const EVENT_EMAIL_ADDRESS_REMOVED = 'email_address_removed';

    // Spaces and inbox assignment (Workspace & Inbox Assignment requirements §5, §7, §8)
    public const EVENT_SPACE_CREATED = 'space_created';

    public const EVENT_SPACE_RENAMED = 'space_renamed';

    public const EVENT_SPACE_ARCHIVED = 'space_archived';

    public const EVENT_SPACE_RESTORED = 'space_restored';

    public const EVENT_INBOX_ASSIGNED = 'inbox_assigned';

    // Setup (FR-1.3)
    public const EVENT_SETUP_COMPLETED = 'setup_completed';

    // Conversations (Phase 2 — FR-2.8 routing, FR-2.9 delivery failures)
    public const EVENT_CONVERSATION_MOVED = 'conversation_moved';

    public const EVENT_DELIVERY_FAILED = 'delivery_failed';

    protected $fillable = [
        'tenant_id',
        'help_desk_id',
        'actor_id',
        'event',
        'subject',
        'meta',
    ];

    protected function casts(): array
    {
        return ['meta' => 'array'];
    }

    public function helpDesk(): BelongsTo
    {
        return $this->belongsTo(HelpDesk::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * How this entry reads, minus the actor's name — the screen renders that in bold in front.
     *
     * The vocabulary lives beside the constants that define it, so adding an event means adding
     * both halves in one file instead of discovering later that the stream shows a bare event
     * key to whoever opens it. Everything it says comes from what was stored at write time.
     */
    public function sentence(): string
    {
        $meta = (array) ($this->meta ?? []);
        $subject = (string) ($this->subject ?? 'someone');

        return match ($this->event) {
            self::EVENT_MEMBER_ADDED => "added {$subject} as ".$this->label($meta, 'role'),
            self::EVENT_MEMBER_ROLE_CHANGED => "changed {$subject}'s role from "
                .($meta['from_label'] ?? $meta['from'] ?? '—').' to '.($meta['to_label'] ?? $meta['to'] ?? '—'),
            self::EVENT_MEMBER_STATUS_CHANGED => (($meta['status'] ?? null) === HelpDeskMember::STATUS_ACTIVE
                ? "reactivated {$subject}"
                : "deactivated {$subject}"),
            self::EVENT_MEMBER_INBOXES_CHANGED => $this->inboxSentence($subject, $meta),
            self::EVENT_MEMBER_REMOVED => "removed {$subject} from the Help Desk",
            self::EVENT_MEMBER_INVITED => "invited {$subject} as ".$this->label($meta, 'role'),
            self::EVENT_INVITE_REVOKED => "revoked the invitation for {$subject}",
            self::EVENT_INVITE_ACCEPTED => 'joined the Help Desk as '.$this->label($meta, 'role'),
            self::EVENT_INBOX_CREATED => "created the inbox {$subject}",
            self::EVENT_INBOX_RENAMED => 'renamed the inbox '.($meta['from'] ?? '—')." to {$subject}",
            self::EVENT_INBOX_ADDRESS_REGENERATED => "gave the inbox {$subject} a new inbound address — "
                .($meta['to'] ?? '—').' replaces '.($meta['from'] ?? '—'),
            self::EVENT_EMAIL_ADDRESS_ADDED => "connected {$subject} to the inbox "
                .($meta['inbox'] ?? '—'),
            // No actor — a message arriving is what proved it, not a person.
            self::EVENT_EMAIL_ADDRESS_CONNECTED => "started receiving forwarded mail from {$subject} in "
                .($meta['inbox'] ?? '—'),
            self::EVENT_EMAIL_ADDRESS_STATUS_CHANGED => (($meta['status'] ?? null) === 'disabled'
                ? "disabled {$subject}"
                : "re-enabled {$subject}"),
            self::EVENT_EMAIL_ADDRESS_REMOVED => "removed {$subject}",
            self::EVENT_SPACE_CREATED => "created the space {$subject}",
            self::EVENT_SPACE_RENAMED => 'renamed the space '.($meta['from'] ?? '—')." to {$subject}",
            self::EVENT_SPACE_ARCHIVED => "archived the space {$subject}",
            self::EVENT_SPACE_RESTORED => "restored the space {$subject}",
            self::EVENT_INBOX_ASSIGNED => $this->assignmentSentence($subject, $meta),
            self::EVENT_SETUP_COMPLETED => 'finished setting up the Help Desk',
            self::EVENT_CONVERSATION_MOVED => "moved {$subject} from "
                .($meta['from'] ?? '—').' to '.($meta['to'] ?? '—'),
            // No actor — the provider told us. The screen renders "Someone" in front of it,
            // which is why this one reads as a statement rather than an action.
            self::EVENT_DELIVERY_FAILED => "could not deliver {$subject} to "
                .($meta['recipient'] ?? 'the customer')
                .(($meta['reason'] ?? null) ? ' — '.$meta['reason'] : ''),
            default => $this->event,
        };
    }

    /**
     * An inbox changing hands between spaces (§7).
     *
     * Three sentences rather than one with blanks in it: an inbox that had no space is being
     * filed for the first time, one being unassigned is being taken out of the structure, and a
     * move between two spaces is the case the confirmation dialog warns about. Reading "moved
     * Support from — to Partner Support" would say none of them.
     *
     * @param  array<string, mixed>  $meta
     */
    private function assignmentSentence(string $subject, array $meta): string
    {
        $from = $meta['from'] ?? null;
        $to = $meta['to'] ?? null;

        return match (true) {
            $to === null => "removed the inbox {$subject} from ".($from ?? 'its space'),
            $from === null => "assigned the inbox {$subject} to {$to}",
            default => "moved the inbox {$subject} from {$from} to {$to}",
        };
    }

    /** @param  array<string, mixed>  $meta */
    private function inboxSentence(string $subject, array $meta): string
    {
        $inboxes = array_filter((array) ($meta['inboxes'] ?? []));

        return $inboxes === []
            // Not the same as "changed" — it is the state that leaves somebody able to open the
            // Help Desk and see nothing, which is worth reading as its own sentence.
            ? "removed {$subject}'s inbox access"
            : "gave {$subject} access to ".implode(', ', $inboxes);
    }

    /** @param  array<string, mixed>  $meta */
    private function label(array $meta, string $key): string
    {
        return (string) ($meta[$key.'_label'] ?? $meta[$key] ?? '—');
    }
}
