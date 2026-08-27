<?php

namespace App\Models;

use App\Models\Concerns\StampsPivotTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * A Module (Module Management §2) — a container grouping related work items inside one
 * project towards a common objective.
 *
 * TENANT-SCOPED (CLAUDE.md §7): BelongsToTenant confines every query to the active workspace
 * and stamps `tenant_id` on insert. Project scoping is applied explicitly on top, because one
 * workspace holds many projects and a module must never cross a project boundary (§15).
 *
 * A module is NOT a small project: it inherits the project's members, permissions and work
 * item configuration (§2), which is why there is no membership role here — only who is
 * involved.
 */
class Module extends Model
{
    use BelongsToTenant, SoftDeletes, StampsPivotTenant;

    public const STATUS_BACKLOG = 'backlog';

    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'tenant_id',
        'project_id',
        'title',
        'description',
        'status',
        'start_date',
        'end_date',
        'lead_user_id',
        'archived_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
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
     * People involved in this module (§5.2).
     *
     * Independent of work item assignment: adding someone here does not assign them anything,
     * and removing them does not unassign them from work (§5.2).
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'module_members')
            ->withPivotValue('tenant_id', $this->pivotTenantId())
            ->withTimestamps();
    }

    /**
     * The work items grouped into this module.
     *
     * A work item is in at most one module (docs/features/module-management.md), enforced by a
     * unique index on the pivot's `work_item_id` rather than by the relation's own shape.
     *
     * Many-to-many: a work item can belong to a functional module and a release module at the
     * same time, which is the case the spec calls out for adopting Plane's model.
     */
    public function workItems(): BelongsToMany
    {
        return $this->belongsToMany(WorkItem::class, 'module_work_items')
            ->withPivotValue('tenant_id', $this->pivotTenantId())
            ->withTimestamps();
    }

    /** Modules of one project — always applied, since a workspace holds many projects. */
    public function scopeForProject(Builder $query, int $projectId): Builder
    {
        return $query->where('project_id', $projectId);
    }

    /** §12.2: archived modules drop out of the default list and the work item picker. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** The configured label and colour for this module's lifecycle state (§6). */
    public function statusMeta(): array
    {
        $statuses = config('projects.module_statuses');

        return $statuses[$this->status] ?? $statuses[self::STATUS_BACKLOG];
    }
}
