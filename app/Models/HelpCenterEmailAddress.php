<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * A customer-facing address that routes into an Inbox
 * (docs/features/help-center.md §6, §7, §11). TENANT-SCOPED.
 *
 * This is an address the company ALREADY publishes — support@, billing@. Nothing here changes
 * it (§10); it records that mail forwarded from it belongs to this Inbox, and how far that
 * forwarding has been proven to work.
 */
class HelpCenterEmailAddress extends Model
{
    use BelongsToTenant;

    /** Added, but the forwarding has not been proven — "Setup Required" in the table (§6). */
    public const STATUS_PENDING = 'pending';

    /** A test has been asked for and nothing has arrived yet. */
    public const STATUS_WAITING = 'waiting';

    /** Mail forwarded from this address has actually reached us (§11). */
    public const STATUS_VERIFIED = 'verified';

    public const STATUS_ERROR = 'error';

    protected $attributes = [
        'status' => self::STATUS_PENDING,
    ];

    protected $fillable = [
        'tenant_id',
        'help_center_inbox_id',
        'email',
        'name',
        'status',
        'verified_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
        ];
    }

    public function inbox(): BelongsTo
    {
        return $this->belongsTo(HelpCenterInbox::class, 'help_center_inbox_id');
    }

    /**
     * Trim, then lowercase (§7).
     *
     * On the MODEL rather than only in the form request, so every path that writes an address
     * normalizes it the same way — including the inbound ingestion of the next phase, which
     * will match on this column and would otherwise miss `Support@Company.com`.
     */
    public function setEmailAttribute(?string $value): void
    {
        $this->attributes['email'] = $value === null ? null : mb_strtolower(trim($value));
    }

    /** How this status should read and be coloured (§6, §11). */
    /** @return array<string, string> */
    public function statusMeta(): array
    {
        $statuses = (array) config('help-center.address_statuses');

        return $statuses[$this->status] ?? ['label' => 'Unknown', 'tone' => 'off'];
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        $meta = $this->statusMeta();

        return [
            'id' => $this->id,
            'email' => $this->email,
            'name' => $this->name,
            'status' => $this->status,
            'status_label' => $meta['label'],
            'status_tone' => $meta['tone'],
        ];
    }
}
