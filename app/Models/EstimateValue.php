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
        'sort_order',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'numeric_value' => 'decimal:2',
            'duration_minutes' => 'integer',
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
    public function rollupValue(): ?float
    {
        if ($this->numeric_value !== null) {
            return (float) $this->numeric_value;
        }

        return $this->duration_minutes !== null ? (float) $this->duration_minutes : null;
    }
}
