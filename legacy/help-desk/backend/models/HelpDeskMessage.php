<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * One message in a conversation, in either direction (FR-2.4). TENANT-SCOPED.
 *
 * Inbound and outbound share a table (decision H18) because everything that makes a message a
 * message is the same in both directions; `direction` is the only thing that differs, and
 * reading a conversation is then one query in date order rather than a union.
 *
 * The RFC 5322 headers are stored exactly as they arrived. They are not decoration: they are
 * how a reply three weeks later is recognised as belonging here rather than starting a new
 * case, and how the same message arriving twice is recognised as the same message.
 */
class HelpDeskMessage extends Model
{
    use BelongsToTenant;

    public const DIRECTION_INBOUND = 'inbound';

    public const DIRECTION_OUTBOUND = 'outbound';

    protected $fillable = [
        'tenant_id',
        'help_desk_id',
        'help_desk_conversation_id',
        'direction',
        'message_id',
        'in_reply_to',
        'references',
        'from_email',
        'from_name',
        'to',
        'cc',
        'subject',
        'body_html',
        'body_text',
        'sent_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'to' => 'array',
            'cc' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(HelpDeskConversation::class, 'help_desk_conversation_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The files that arrived with it (Inbound Email requirements §11.6).
     *
     * Includes the ones that were too large to keep: those rows exist without a file precisely
     * so a conversation can say "the customer sent this and we did not store it".
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(HelpDeskMessageAttachment::class, 'help_desk_message_id');
    }

    public function isInbound(): bool
    {
        return $this->direction === self::DIRECTION_INBOUND;
    }
}
