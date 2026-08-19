<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * What the mail provider said happened to something we sent (FR-2.9). TENANT-SCOPED.
 *
 * One row per recipient per event, append-only. Which statuses count as a FAILURE is config
 * (`help-desk.delivery.failure_statuses`) rather than a constant here: a deferral is not a
 * failure — mail servers defer constantly and it usually resolves itself — and where that line
 * sits is an operational judgement somebody may want to change without a deployment.
 */
class HelpDeskEmailDelivery extends Model
{
    use BelongsToTenant;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_DEFERRED = 'deferred';

    public const STATUS_BOUNCED = 'bounced';

    public const STATUS_COMPLAINED = 'complained';

    public const STATUS_FAILED = 'failed';

    /** @var array<int, string> */
    public const STATUSES = [
        self::STATUS_QUEUED,
        self::STATUS_DELIVERED,
        self::STATUS_DEFERRED,
        self::STATUS_BOUNCED,
        self::STATUS_COMPLAINED,
        self::STATUS_FAILED,
    ];

    protected $fillable = [
        'tenant_id',
        'help_desk_id',
        'help_desk_conversation_id',
        'help_desk_message_id',
        'status',
        'recipient',
        'reason',
        'event_id',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(HelpDeskConversation::class, 'help_desk_conversation_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(HelpDeskMessage::class, 'help_desk_message_id');
    }

    public function isFailure(): bool
    {
        return in_array($this->status, self::failureStatuses(), true);
    }

    public function isDelivered(): bool
    {
        return $this->status === self::STATUS_DELIVERED;
    }

    /** @return array<int, string> */
    public static function failureStatuses(): array
    {
        return (array) config('help-desk.delivery.failure_statuses', [
            self::STATUS_BOUNCED, self::STATUS_FAILED, self::STATUS_COMPLAINED,
        ]);
    }

    /** How the event reads on a conversation. */
    public function summary(): string
    {
        $reason = trim((string) $this->reason);

        return $reason !== ''
            ? ucfirst($this->status).' — '.$reason
            : ucfirst($this->status);
    }
}
