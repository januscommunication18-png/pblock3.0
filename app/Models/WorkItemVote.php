<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * One person's vote on one work item (POC toolbar).
 *
 * TENANT-SCOPED (CLAUDE.md §7). One row per person per item — `UNIQUE(work_item_id, user_id)`
 * backs that at the database — so `value` is the whole opinion: switching sides updates it,
 * and taking the vote back deletes the row rather than storing a third state meaning "none".
 */
class WorkItemVote extends Model
{
    use BelongsToTenant;

    public const UP = 'up';

    public const DOWN = 'down';

    /** The only two things a vote can be. */
    public const VALUES = [self::UP, self::DOWN];

    protected $fillable = ['tenant_id', 'work_item_id', 'user_id', 'value'];

    public function workItem(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
