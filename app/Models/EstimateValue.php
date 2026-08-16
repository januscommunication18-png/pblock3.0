<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * One selectable estimate (Estimation §33). TENANT-SCOPED.
 *
 * `active` is what §21 asks for: a value assigned to work items is archived rather than
 * deleted, so it stops being offered while every work item already carrying it still reads
 * correctly. Nothing in this app hard-deletes one that is in use.
 */
class EstimateValue extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'project_estimation_id',
        'label',
        'numeric_value',
        'duration_minutes',
        'capacity_hours',
        'sort_order',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'numeric_value' => 'decimal:2',
            'duration_minutes' => 'integer',
            'capacity_hours' => 'decimal:2',
            'sort_order' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function estimation(): BelongsTo
    {
        return $this->belongsTo(ProjectEstimation::class, 'project_estimation_id');
    }

    public function workItems(): HasMany
    {
        return $this->hasMany(WorkItem::class, 'estimate_value_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /**
     * The number this value contributes to a rollup (§32), or null when it contributes none.
     *
     * A category has no such number by design — XS cannot be added to S — so callers must
     * handle null rather than treating the absence as zero, which would silently report a
     * category project as having estimated nothing.
     */
    /**
     * The working hours this estimate is worth, or null when it has none
     * (docs/features/work-capacity.md, CAP-D1/CAP-D2).
     *
     * A `time` value already IS hours, so it reads `duration_minutes` and ignores any
     * configured `capacity_hours` — asking for the figure twice is asking for two answers that
     * are free to disagree. Points and categories have no inherent duration and use the
     * configured column.
     *
     * Null means UNMAPPED, and every caller must carry it as such rather than as zero: an
     * unmapped estimate is work whose size nobody has stated, which is not the same as work
     * that takes no time.
     */
    public function capacityHours(): ?float
    {
        if ($this->duration_minutes !== null) {
            return round($this->duration_minutes / 60, 2);
        }

        return $this->capacity_hours !== null ? (float) $this->capacity_hours : null;
    }

    /** Whether this value needs a capacity figure configured before it can be planned with. */
    public function needsCapacityMapping(): bool
    {
        return $this->duration_minutes === null && $this->capacity_hours === null;
    }

    public function rollupValue(): ?float
    {
        if ($this->numeric_value !== null) {
            return (float) $this->numeric_value;
        }

        return $this->duration_minutes !== null ? (float) $this->duration_minutes : null;
    }
}
