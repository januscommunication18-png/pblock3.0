<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * One email belonging to a Request (docs/features/help-center.md, P6/P9). TENANT-SCOPED.
 *
 * The customer's original sender, To and Cc are preserved exactly (P1 §20 rules 9 and 10) —
 * replying to everyone who was on the original message is impossible if only our own inbound
 * address was recorded.
 */
class HelpCenterMessage extends Model
{
    use BelongsToTenant;

    public const DIRECTION_INBOUND = 'inbound';

    public const DIRECTION_OUTBOUND = 'outbound';

    protected $attributes = [
        'direction' => self::DIRECTION_INBOUND,
    ];

    protected $fillable = [
        'tenant_id',
        'help_center_request_id',
        'direction',
        'from_email',
        'from_name',
        'author_email',
        'author_name',
        'delivery_status',
        'delivery_error',
        'delivered_at',
        'reply_to',
        'to_recipients',
        'cc_recipients',
        'subject',
        'body_text',
        'body_html',
        'raw_text',
        'raw_html',
        'quote_stripped',
        'attachments_skipped',
        'attachments_skipped_detail',
        'message_id',
        'provider_message_id',
        'received_at',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'quote_stripped' => 'boolean',
            'attachments_skipped' => 'integer',
            'attachments_skipped_detail' => 'array',
            'to_recipients' => 'array',
            'cc_recipients' => 'array',
            'received_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(HelpCenterRequest::class, 'help_center_request_id');
    }

    /** The files that came with this email (P66), oldest first. */
    public function attachments(): HasMany
    {
        return $this->hasMany(HelpCenterMessageAttachment::class, 'help_center_message_id')
            ->orderBy('id');
    }

    /** Normalized like every other address in the module, so matching is reliable. */
    public function setFromEmailAttribute(?string $value): void
    {
        $this->attributes['from_email'] = $value === null ? null : mb_strtolower(trim($value));
    }

    public const DELIVERY_SENT = 'sent';

    public const DELIVERY_FAILED = 'failed';

    /** Did this reply reach the provider? Null on inbound rows — we never delivered those. */
    public function hasFailed(): bool
    {
        return $this->delivery_status === self::DELIVERY_FAILED;
    }

    public function isInbound(): bool
    {
        return $this->direction === self::DIRECTION_INBOUND;
    }

    /**
     * Something to show in a list.
     *
     * Prefers the plain-text part: the HTML part of a real email is a wrapper of tables and
     * inline styles, and the first 140 characters of that is markup rather than words.
     */
    public function preview(int $length = 140): string
    {
        $body = $this->body_text ?: strip_tags((string) $this->body_html);

        $body = trim(preg_replace('/\s+/u', ' ', $body) ?? '');

        return mb_strlen($body) > $length ? mb_substr($body, 0, $length).'…' : $body;
    }
}
