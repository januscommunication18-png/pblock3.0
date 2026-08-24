<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * A day the SLA clock does not run (docs/features/helpdesk-sla.md, §7). TENANT-SCOPED.
 */
class HelpCenterSlaHoliday extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'help_center_space_id',
        'name',
        'date',
        'repeats_annually',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'repeats_annually' => 'boolean',
        ];
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(HelpCenterSpace::class, 'help_center_space_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('date');
    }

    /**
     * Does this holiday fall on the given LOCAL date?
     *
     * The caller passes a date already in the calendar's timezone. Christmas is the 25th of
     * December where the team works, not wherever the server happens to be — comparing against a
     * UTC instant would move the holiday for half the world.
     */
    public function falls(Carbon $localDate): bool
    {
        if ($this->repeats_annually) {
            return $this->date->format('m-d') === $localDate->format('m-d');
        }

        return $this->date->isSameDay($localDate);
    }
}
