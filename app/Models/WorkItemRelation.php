<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * A dependency or relation between two work items (Collaboration spec §27–§36).
 *
 * TENANT-SCOPED (CLAUDE.md §7). Rows are stored in their canonical direction only — see the
 * migration — so `blocked_by` never appears here; it is `blocking` read from the other end.
 */
class WorkItemRelation extends Model
{
    use BelongsToTenant;

    /** Canonical, stored types. */
    public const TYPE_BLOCKING = 'blocking';

    public const TYPE_RELATED = 'related';

    public const TYPE_DUPLICATE_OF = 'duplicate_of';

    /** Derived, never stored: what the other end of each canonical type is called. */
    public const TYPE_BLOCKED_BY = 'blocked_by';

    public const TYPE_DUPLICATED_BY = 'duplicated_by';

    /** The types a user may ask for when adding (§30, §34, §35). */
    public const ADDABLE = [
        self::TYPE_BLOCKING,
        self::TYPE_BLOCKED_BY,
        self::TYPE_RELATED,
        self::TYPE_DUPLICATE_OF,
    ];

    protected $fillable = [
        'tenant_id',
        'project_id',
        'work_item_id',
        'related_work_item_id',
        'relation_type',
        'created_by',
    ];

    public function workItem(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class, 'work_item_id');
    }

    public function relatedWorkItem(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class, 'related_work_item_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
