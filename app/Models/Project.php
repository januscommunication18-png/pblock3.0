<?php

namespace App\Models;

use App\Models\Concerns\StampsPivotTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * A Project (Phase 4, spec §2/§5). Belongs to exactly one workspace (the tenant).
 *
 * TENANT-SCOPED (CLAUDE.md §7): the BelongsToTenant global scope confines every query to
 * the active workspace and stamps `tenant_id` on insert — controllers never filter by
 * workspace by hand, and a foreign-workspace id 404s through route-model binding instead
 * of leaking (PRJ-032).
 */
class Project extends Model
{
    use BelongsToTenant, StampsPivotTenant;

    public const VISIBILITY_PUBLIC = 'public';

    public const VISIBILITY_PRIVATE = 'private';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'tenant_id',
        'name',
        'identifier',
        'description',
        'visibility',
        'lead_user_id',
        'default_assignee_id',
        'work_item_view',
        'features',
        'cover_url',
        'cover_gradient',
        'timezone',
        'status',
        'created_by',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
            'features' => 'array',
            // Dates, not datetimes: a project starts on a day, not at an instant, and casting
            // them is what lets the Overview format them without parsing strings by hand.
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    /** The project's own state (Draft, In progress, …) — not a work item state. */
    public function state(): BelongsTo
    {
        return $this->belongsTo(ProjectState::class, 'state_id');
    }

    public function priority(): BelongsTo
    {
        return $this->belongsTo(ProjectPriority::class, 'priority_id');
    }

    /** Live projects — archived ones stay readable, but drop out of every default list. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * The projects this user may see (§18, the same rule as ProjectPolicy@view).
     *
     * Workspace owners and admins keep visibility across every project; everyone else needs an
     * explicit project membership. A scope rather than a per-row policy check, because the
     * callers here are queries that must not load a row to decide whether it may be seen.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $role = WorkspaceMembership::query()
            ->where('workspace_id', $this->tenant_id ?? tenant()?->getTenantKey())
            ->where('user_id', $user->id)
            ->where('status', WorkspaceMembership::STATUS_ACTIVE)
            ->value('role');

        if (in_array($role, [WorkspaceMembership::ROLE_OWNER, 'admin'], true)) {
            return $query;
        }

        return $query->whereIn(
            $query->qualifyColumn('id'),
            ProjectMember::query()->where('user_id', $user->id)->select('project_id'),
        );
    }

    /**
     * This project's feature switches, catalog defaults filled in (PRJ-042).
     *
     * Merged rather than read straight out of the column so a feature added to the catalog
     * later is immediately readable on every existing project — no backfill, no null checks
     * scattered through the callers.
     *
     * @return array<string, bool>
     */
    public function featureFlags(): array
    {
        $stored = is_array($this->features) ? $this->features : [];

        return collect(config('projects.features'))
            ->map(fn (array $meta, string $key) => (bool) ($stored[$key] ?? $meta['default'] ?? false))
            ->all();
    }

    /** Is this capability switched on for this project? */
    public function featureEnabled(string $key): bool
    {
        return $this->featureFlags()[$key] ?? false;
    }

    /**
     * Does the plan behind this project include a gated feature (Cycles §13)?
     *
     * There is no subscription model yet, so the answer comes from config. When a real plan
     * check arrives it replaces this method body and nothing else moves.
     */
    public function entitledTo(string $entitlement): bool
    {
        return (bool) config("projects.entitlements.{$entitlement}", true);
    }

    /** Work item groupings, when Modules is enabled for this project (Modules §2). */
    public function modules(): HasMany
    {
        return $this->hasMany(Module::class);
    }

    /** Sprint-style time boxes, when Cycles is enabled for this project (Cycles §5). */
    public function cycles(): HasMany
    {
        return $this->hasMany(Cycle::class);
    }

    /** Work item visibility for ordinary members (General spec §10). */
    public const VIEW_ALL = 'all';

    public const VIEW_ASSIGNED = 'assigned';

    /** Does this project restrict members to the work items assigned to them? */
    public function restrictsToAssigned(): bool
    {
        return $this->work_item_view === self::VIEW_ASSIGNED;
    }

    /** Applied when a work item is created with no assignee chosen (§12). */
    public function defaultAssignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'default_assignee_id');
    }

    /**
     * Members who receive this project's notifications (§13).
     *
     * `withPivotValue` stamps the tenant on every row the relation writes — the pivot is
     * tenant-scoped like everything else, and sync() would otherwise insert without it.
     */
    public function subscribers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_subscribers')
            ->withPivotValue('tenant_id', $this->pivotTenantId())
            ->withTimestamps();
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lead_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function members(): HasMany
    {
        return $this->hasMany(ProjectMember::class);
    }

    /** Work-item states configured for this project (Project Settings → States). */
    public function states(): HasMany
    {
        return $this->hasMany(ProjectItemState::class);
    }

    /** Work-item labels configured for this project (Project Settings → Labels). */
    public function labels(): HasMany
    {
        return $this->hasMany(ProjectItemLabel::class);
    }

    /** Work items tracked in this project (Phase 5). */
    public function workItems(): HasMany
    {
        return $this->hasMany(WorkItem::class);
    }

    public function isPublic(): bool
    {
        return $this->visibility === self::VISIBILITY_PUBLIC;
    }

    /** Uppercase initial for the coverless card / avatar tile. */
    public function initial(): string
    {
        return strtoupper(mb_substr(trim((string) $this->name), 0, 1)) ?: 'P';
    }
}
