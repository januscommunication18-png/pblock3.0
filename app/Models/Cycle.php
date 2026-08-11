<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * A Cycle — a time-boxed sprint inside one project (Cycles §1).
 *
 * TENANT-SCOPED (CLAUDE.md §7): BelongsToTenant confines every query to the active workspace
 * and stamps `tenant_id` on insert. Project scoping is applied explicitly on top, because one
 * workspace holds many projects and a cycle must never cross a project boundary (§8.3.6).
 *
 * Status is DERIVED from the dates, never stored — see the class docblock on the migration
 * and docs/features/cycles.md. A stored column would need a scheduled job to stay true to
 * §9's own definition.
 */
class Cycle extends Model
{
    use BelongsToTenant;

    public const STATUS_UPCOMING = 'upcoming';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'tenant_id',
        'project_id',
        'name',
        'description',
        'start_date',
        'end_date',
        'created_by',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The work items planned into this cycle (§7.2). */
    public function workItems(): HasMany
    {
        return $this->hasMany(WorkItem::class);
    }

    /** Cycles of one project — always applied, since a workspace holds many projects. */
    public function scopeForProject(Builder $query, int $projectId): Builder
    {
        return $query->where('project_id', $projectId);
    }

    /**
     * Upcoming / Active / Completed (§9), from the dates and today.
     *
     * The range is inclusive at both ends (§9.2), so a one-day cycle is Active on its day.
     */
    public function status(?Carbon $today = null): string
    {
        $today ??= Carbon::today();
        $today = $today->copy()->startOfDay();

        if ($this->start_date && $today->lt($this->start_date->copy()->startOfDay())) {
            return self::STATUS_UPCOMING;
        }

        if ($this->end_date && $today->gt($this->end_date->copy()->startOfDay())) {
            return self::STATUS_COMPLETED;
        }

        return self::STATUS_ACTIVE;
    }

    public function isCompleted(): bool
    {
        return $this->status() === self::STATUS_COMPLETED;
    }

    /**
     * Cycles that can still take new work (§8.2/§8.3.5) — everything not past its end date.
     *
     * Expressed in SQL rather than by filtering a loaded collection, so the picker and the
     * overlap check can both use it as part of a larger query.
     */
    public function scopeAssignable(Builder $query, ?Carbon $today = null): Builder
    {
        return $query->whereDate('end_date', '>=', ($today ?? Carbon::today())->toDateString());
    }

    /** Whole days from today to the end date; 0 once the cycle is over (§5.1). */
    public function daysRemaining(?Carbon $today = null): int
    {
        $today = ($today ?? Carbon::today())->copy()->startOfDay();

        return $this->end_date && $this->end_date->copy()->startOfDay()->gte($today)
            ? (int) $today->diffInDays($this->end_date->copy()->startOfDay())
            : 0;
    }
}
