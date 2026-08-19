<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * An inbox inside a Help Desk (docs/features/help-desk.md, FR-1.7). TENANT-SCOPED.
 *
 * A name and nothing else in Phase 1 — that is all inbox-level access needs. The email address,
 * channel and routing rules that make an inbox receive anything are Phase 2, and they extend
 * this row rather than replace it.
 */
class HelpDeskInbox extends Model
{
    use BelongsToTenant;

    /**
     * `inbound_address` is deliberately ABSENT.
     *
     * It is generated and read-only (Inbound Email requirements §2) — see
     * InboundAddressGenerator, which is the only thing that writes it. Leaving it out of
     * `$fillable` means a request body carrying an `inbound_address` cannot quietly repoint an
     * inbox that somebody's mail provider is already forwarding to.
     */
    protected $fillable = [
        'tenant_id',
        'help_desk_id',
        // Which space this inbox belongs to (Workspace & Inbox Assignment §7). One space, or
        // none — never two.
        'help_desk_space_id',
        'name',
        // Phase 2 (FR-2.3/2.5): who replies come from, and where new conversations land.
        'outbound_from_name',
        'outbound_from_address',
        'default_assignee_id',
        'created_by',
    ];

    public function helpDesk(): BelongsTo
    {
        return $this->belongsTo(HelpDesk::class);
    }

    /**
     * The space this inbox belongs to (Workspace & Inbox Assignment §7), or null.
     *
     * Null is a real state, not a gap: §12 exists for an inbox that has not been assigned yet.
     */
    public function space(): BelongsTo
    {
        return $this->belongsTo(HelpDeskSpace::class, 'help_desk_space_id');
    }

    /** The members explicitly given this inbox. Admins and Managers reach it without a row. */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(HelpDeskMember::class, 'help_desk_member_inboxes')
            ->withTimestamps();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Where new conversations in this inbox land, if anywhere (FR-2.5). */
    public function defaultAssignee(): BelongsTo
    {
        return $this->belongsTo(HelpDeskMember::class, 'default_assignee_id');
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(HelpDeskConversation::class, 'help_desk_inbox_id');
    }

    /**
     * The customer-facing addresses forwarded here (Inbound Email requirements §10).
     *
     * The other half of `inbound_address`: this inbox receives AT the generated address, and
     * these are the addresses customers actually write to, each with its own connection status.
     */
    public function emailAddresses(): HasMany
    {
        return $this->hasMany(HelpDeskEmailAddress::class, 'help_desk_inbox_id');
    }

    /**
     * What to paste into a mail provider's forwarding rule (§6, "Copy forwarding instructions").
     *
     * Plain text rather than a link to a help page: the person configuring the forwarding is
     * usually not the person who opened this screen — it is whoever administers the customer's
     * mail — and what they need travels in an email or a ticket, not in a URL behind a login.
     */
    public function forwardingInstructions(?string $customerAddress = null): string
    {
        $from = $customerAddress ?: 'your support address';

        return implode("\n", [
            "Forward mail from {$from} to {$this->inbound_address}",
            '',
            "1. Open the mail provider that hosts {$from}.",
            '2. Add a forwarding rule (Gmail: Settings › Forwarding; Microsoft 365: Mail › Forwarding;',
            '   cPanel: Email › Forwarders).',
            "3. Forward to: {$this->inbound_address}",
            '4. Confirm the forward if your provider asks — the confirmation mail arrives in this inbox.',
            '',
            "Mail forwarded there becomes a conversation in the {$this->name} inbox.",
        ]);
    }

    /**
     * Who replies from this inbox appear to come from (FR-2.3).
     *
     * Falls back to the application's configured sender rather than leaving an inbox unable to
     * answer: an outbound identity is something an administrator gets around to, and a reply
     * that cannot be sent because a settings field is blank is a worse failure than one sent
     * from the default address.
     *
     * @return array{address: string, name: string}
     */
    public function sender(): array
    {
        return [
            'address' => (string) ($this->outbound_from_address ?: $this->inbound_address ?: config('mail.from.address')),
            'name' => (string) ($this->outbound_from_name ?: $this->name),
        ];
    }
}
