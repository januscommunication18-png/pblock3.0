<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * One person named in one piece of rich text (docs/features/mentions.md).
 *
 * TENANT-SCOPED (CLAUDE.md §7). This row — not the `<span data-user-id>` in the stored HTML —
 * is what the application means by "was mentioned": §23/§24 require the backend to validate
 * every reference before it counts, so the markup is presentation and this is the record.
 */
class Mention extends Model
{
    use BelongsToTenant;

    /** A work item's description. */
    public const SOURCE_WORK_ITEM = 'work_item';

    /** A comment on a work item. */
    public const SOURCE_COMMENT = 'comment';

    /** The surfaces that can carry a mention today; §2 lists more to come. */
    public const SOURCES = [self::SOURCE_WORK_ITEM, self::SOURCE_COMMENT];

    protected $fillable = [
        'tenant_id', 'source_type', 'source_id', 'work_item_id', 'user_id', 'mentioned_by',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'mentioned_by');
    }

    public function workItem(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class);
    }

    /** Every mention in one piece of content. */
    public function scopeForSource(Builder $query, string $type, int $id): Builder
    {
        return $query->where('source_type', $type)->where('source_id', $id);
    }
}
