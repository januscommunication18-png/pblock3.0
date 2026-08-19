<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * One customer conversation (docs/features/help-desk.md — Phase 2, FR-2.4/2.7). TENANT-SCOPED.
 *
 * A conversation IS the thread (decision H17): the identifiers that tie replies together live
 * on its messages, and this row is what they all point at. It is created by the first message
 * that cannot be attached to an existing one, and it keeps its number for life — including
 * across a move to another inbox (FR-2.8), which is why the number is allocated per Help Desk
 * rather than per inbox.
 *
 * Soft-deleted: support history is what the phase's general data rules say must remain
 * auditable.
 */
class HelpDeskConversation extends Model
{
    use BelongsToTenant, SoftDeletes;

    /** Phase 3 owns the lifecycle; this is the state a conversation starts in. */
    public const STATUS_OPEN = 'open';

    protected $fillable = [
        'tenant_id',
        'help_desk_id',
        'help_desk_inbox_id',
        'number',
        'subject',
        'status',
        'customer_email',
        'customer_name',
        'assignee_id',
        'last_message_at',
        'delivery_failed_at',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'delivery_failed_at' => 'datetime',
        ];
    }

    public function helpDesk(): BelongsTo
    {
        return $this->belongsTo(HelpDesk::class);
    }

    public function inbox(): BelongsTo
    {
        return $this->belongsTo(HelpDeskInbox::class, 'help_desk_inbox_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(HelpDeskMember::class, 'assignee_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(HelpDeskMessage::class, 'help_desk_conversation_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(HelpDeskEmailDelivery::class, 'help_desk_conversation_id');
    }

    /** Is a reply on this conversation currently known to have failed (FR-2.9)? */
    public function hasDeliveryFailure(): bool
    {
        return $this->delivery_failed_at !== null;
    }

    /** How the conversation is referred to out loud. */
    public function reference(): string
    {
        return '#'.$this->number;
    }
}
