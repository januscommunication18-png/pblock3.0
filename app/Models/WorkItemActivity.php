<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * One entry in a work item's activity feed (Work Items §6). TENANT-SCOPED.
 *
 * Append-only by intent: entries are written by WorkItemActivityRecorder and never updated,
 * so the feed is a truthful record of what happened rather than a mutable summary.
 */
class WorkItemActivity extends Model
{
    use BelongsToTenant;

    /** Spec §8 names the table `work_item_activity`, not Laravel's plural default. */
    protected $table = 'work_item_activity';

    /** The work item itself was created (§11.2). */
    public const EVENT_CREATED = 'created';

    /** A property changed; `field`, `old_value` and `new_value` carry the detail. */
    public const EVENT_UPDATED = 'updated';

    /**
     * Someone voted, switched sides, or took their vote back.
     *
     * Its own event rather than a plain `updated` row: a vote is not a property of the work
     * item, and the audit trail is asked "who voted, and how did it change" as a question of
     * its own. `old_value` / `new_value` carry `up`, `down` or `none`.
     */
    public const EVENT_VOTE_CHANGED = 'work_item_vote_changed';

    protected $fillable = [
        'tenant_id',
        'work_item_id',
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

    public function workItem(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
