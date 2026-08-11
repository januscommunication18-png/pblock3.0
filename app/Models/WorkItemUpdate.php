<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/** A structured status update on a work item (spec §8). TENANT-SCOPED. */
class WorkItemUpdate extends Model
{
    use BelongsToTenant, SoftDeletes;

    public const STATUS_ON_TRACK = 'on_track';

    public const STATUS_AT_RISK = 'at_risk';

    public const STATUS_OFF_TRACK = 'off_track';

    public const STATUSES = [self::STATUS_ON_TRACK, self::STATUS_AT_RISK, self::STATUS_OFF_TRACK];

    protected $fillable = [
        'tenant_id', 'project_id', 'work_item_id', 'author_id', 'status', 'content',
        'progress_percent', 'completed_subtasks', 'total_subtasks', 'edited_at',
    ];

    protected function casts(): array
    {
        return [
            'edited_at' => 'datetime',
            'progress_percent' => 'integer',
            'completed_subtasks' => 'integer',
            'total_subtasks' => 'integer',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /** Never colour alone (§8.4) — the label travels with the status everywhere. */
    public function label(): string
    {
        return match ($this->status) {
            self::STATUS_AT_RISK => 'At Risk',
            self::STATUS_OFF_TRACK => 'Off Track',
            default => 'On Track',
        };
    }
}
