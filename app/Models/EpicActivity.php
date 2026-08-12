<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * One entry in an Epic's activity feed (Epic §8/§22). TENANT-SCOPED.
 *
 * Append-only by intent: entries are written by EpicActivityRecorder and never updated, so the
 * feed is a truthful record of what happened rather than a mutable summary. Labels are
 * resolved and stored at write time, which is why renaming a lead or a work item later cannot
 * rewrite what the history says.
 */
class EpicActivity extends Model
{
    use BelongsToTenant;

    protected $table = 'epic_activity';

    /** §22's vocabulary. `field` carries which property, for the update events. */
    public const EVENT_CREATED = 'created';

    public const EVENT_UPDATED = 'updated';

    public const EVENT_ARCHIVED = 'archived';

    public const EVENT_RESTORED = 'restored';

    public const EVENT_ITEM_ADDED = 'work_item_added';

    public const EVENT_ITEM_REMOVED = 'work_item_removed';

    protected $fillable = [
        'tenant_id',
        'epic_id',
        'actor_id',
        'event',
        'field',
        'old_value',
        'new_value',
        'meta',
    ];

    protected function casts(): array
    {
        return ['meta' => 'array'];
    }

    public function epic(): BelongsTo
    {
        return $this->belongsTo(Epic::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
