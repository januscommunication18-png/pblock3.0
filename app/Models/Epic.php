<?php

namespace App\Models;

use App\Models\Concerns\StampsPivotTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * An Epic (Epic §5) — a larger initiative, objective or deliverable that work items
 * contribute to and that may span several cycles.
 *
 * TENANT-SCOPED (CLAUDE.md §7): BelongsToTenant confines every query to the active workspace
 * and stamps `tenant_id` on insert. Project scoping is applied explicitly on top, because one
 * workspace holds many projects and §5 gives each Epic exactly one.
 *
 * Epic is independent of Module and Cycle (§11/§12). Nothing on this model reads or writes
 * either, and that is the point rather than an omission: they answer different questions, and
 * coupling them would force a hierarchy the spec explicitly refuses.
 */
class Epic extends Model
{
    use BelongsToTenant, SoftDeletes, StampsPivotTenant;

    public const STATUS_BACKLOG = 'backlog';

    public const STATUS_COMPLETED = 'completed';

    public const PRIORITY_NONE = 'none';

    protected $fillable = [
        'tenant_id',
        'project_id',
        'identifier',
        'title',
        'description',
        'status',
        'priority',
        'start_date',
        'target_date',
        'lead_user_id',
        'archived_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'identifier' => 'integer',
            'start_date' => 'date',
            'target_date' => 'date',
            'archived_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lead_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * People involved in this epic (§5).
     *
     * Involvement only, like a module's members: adding someone assigns them nothing, and
     * removing them unassigns them from nothing.
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'epic_members')
            ->withPivotValue('tenant_id', $this->pivotTenantId())
            ->withTimestamps();
    }

    /**
     * The work items in this epic (§9).
     *
     * hasMany, not belongsToMany: §9 gives a work item ZERO OR ONE epic in Phase 1, so the
     * association is a column on the work item — the same shape as `cycle_id`, and
     * deliberately not the many-to-many that modules use.
     */
    public function workItems(): HasMany
    {
        return $this->hasMany(WorkItem::class);
    }

    /** Epics of one project — always applied, since a workspace holds many projects. */
    public function scopeForProject(Builder $query, int $projectId): Builder
    {
        return $query->where('project_id', $projectId);
    }

    /** §16: archived epics drop out of the active list and the work item selector. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** The configured label and colour for this epic's lifecycle status (§5). */
    public function statusMeta(): array
    {
        $statuses = config('projects.epic_statuses');

        return $statuses[$this->status] ?? $statuses[self::STATUS_BACKLOG];
    }

    /**
     * The next per-project identifier (§6: unique within the Project).
     *
     * Read inside the caller's transaction with a row lock on the project's existing epics, so
     * two people creating an epic at the same moment cannot be handed the same number. The
     * unique index is still the last word — this only keeps the common case from ever
     * reaching it.
     */
    public static function nextIdentifier(int $projectId): int
    {
        $max = static::query()
            ->withTrashed()
            ->where('project_id', $projectId)
            ->lockForUpdate()
            ->max('identifier');

        return (int) $max + 1;
    }
}
