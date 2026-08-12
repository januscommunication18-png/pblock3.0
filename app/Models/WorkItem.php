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
 * A Work Item (Phase 5) — the unit of work tracked inside a Project.
 *
 * TENANT-SCOPED (CLAUDE.md §7): BelongsToTenant confines every query to the active workspace
 * and stamps `tenant_id` on insert. Project scoping is on top of that and is always applied
 * explicitly (`forProject`), because one workspace holds many projects (requirements §7).
 *
 * `identifier` is the human-facing ID: a plain unique number (1, 2, 3 …) drawn from the
 * workspace counter, so it names exactly one work item anywhere in the workspace. It is
 * assigned once by WorkItemCreator and never recomputed — moving or renaming a project must
 * not renumber or rewrite existing work item IDs.
 */
class WorkItem extends Model
{
    use BelongsToTenant, StampsPivotTenant;

    public const PRIORITY_NONE = 'none';

    protected $fillable = [
        'tenant_id',
        'project_id',
        'sequence_no',
        'identifier',
        'title',
        'description',
        'state_id',
        'priority',
        'start_date',
        'due_date',
        'parent_id',
        'cycle_id',
        'cycle_assigned_by',
        'cycle_assigned_at',
        'epic_id',
        'estimate_value_id',
        'created_by',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'sequence_no' => 'integer',
            'start_date' => 'date',
            'due_date' => 'date',
            'cycle_assigned_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * The modules this item belongs to (Module Management §9.3).
     *
     * MANY, unlike `cycle` — a work item can sit in a functional module and a release module
     * at once, which is the case the spec adopts Plane's model for.
     */
    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(Module::class, 'module_work_items')
            ->withPivotValue('tenant_id', $this->pivotTenantId())
            ->withTimestamps();
    }

    /**
     * The one epic this item contributes to, if any (Epic §9).
     *
     * ONE, like `cycle` and unlike `modules` — §9 gives a work item zero or one epic in
     * Phase 1. Independent of both the others (§11/§12): setting this never reads or writes
     * `cycle_id` or the module pivot, and nothing here should ever make it.
     */
    public function epic(): BelongsTo
    {
        return $this->belongsTo(Epic::class);
    }

    /**
     * This item's estimate, if it has one (Estimation §33).
     *
     * One value, never several: §36 refuses points AND a T-shirt size AND a duration on one
     * item, because nothing downstream could then aggregate it honestly.
     */
    public function estimateValue(): BelongsTo
    {
        return $this->belongsTo(EstimateValue::class, 'estimate_value_id');
    }

    /** The one cycle this item is planned into, if any (Cycles §8.3.1). */
    public function cycle(): BelongsTo
    {
        return $this->belongsTo(Cycle::class);
    }

    /**
     * Is this item still outstanding (Cycles §10)? Anything whose state is not in a
     * completed or cancelled group — including an item with no state at all, which has
     * certainly not been finished.
     */
    public function scopeIncomplete(Builder $query): Builder
    {
        return $query->whereDoesntHave('state', fn ($s) => $s->whereIn('group', ['completed', 'cancelled']));
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function state(): BelongsTo
    {
        return $this->belongsTo(ProjectItemState::class, 'state_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** Sub-work items (spec §5). The list/detail UI for these lands in a later slice. */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'work_item_assignees')->withTimestamps();
    }

    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(ProjectItemLabel::class, 'work_item_labels', 'work_item_id', 'label_id')
            ->withTimestamps();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Work items of one project — always applied, since a workspace holds many projects. */
    public function scopeForProject(Builder $query, int $projectId): Builder
    {
        return $query->where('project_id', $projectId);
    }

    /** Live items only; archived ones drop out of the default list but keep their data. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
