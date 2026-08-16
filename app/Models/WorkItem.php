<?php

namespace App\Models;

use App\Models\Concerns\StampsPivotTenant;
use App\Models\Scopes\ExcludesDrafts;
use App\Observers\WorkItemCapacityObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
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
 *
 * DRAFTS (docs/features/drafts.md): a row with `is_draft` set is a work item captured before it
 * has a project — no project, no state, no ID number, private to its author until published.
 * The ExcludesDrafts global scope keeps those rows out of every query in the application, so
 * "work item" means what it always did everywhere except `drafts()`.
 */
#[ObservedBy(WorkItemCapacityObserver::class)]
class WorkItem extends Model
{
    use BelongsToTenant, StampsPivotTenant;

    public const PRIORITY_NONE = 'none';

    protected $fillable = [
        'tenant_id',
        'project_id',
        'is_draft',
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
        'capacity_hours',
        'created_by',
        'archived_at',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new ExcludesDrafts);
    }

    protected function casts(): array
    {
        return [
            'is_draft' => 'boolean',
            'sequence_no' => 'integer',
            'start_date' => 'date',
            'due_date' => 'date',
            'cycle_assigned_at' => 'datetime',
            'archived_at' => 'datetime',
            'capacity_hours' => 'decimal:2',
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
     * The project pages this work item points at.
     *
     * MANY, both ways: a work item can cite a spec, a decision record and the meeting it was
     * agreed in, and each of those is cited by plenty of other work items.
     */
    public function pages(): BelongsToMany
    {
        return $this->belongsToMany(ProjectPage::class, 'work_item_pages', 'work_item_id', 'project_page_id')
            ->withPivotValue('tenant_id', $this->pivotTenantId())
            ->withTimestamps();
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

    /** Votes on this item (POC toolbar) — at most one per person, up or down. */
    public function votes(): HasMany
    {
        return $this->hasMany(WorkItemVote::class);
    }

    /** People following THIS item, as distinct from the project it lives in. */
    public function subscribers(): HasMany
    {
        return $this->hasMany(WorkItemSubscriber::class);
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

    /**
     * Drafts instead of work items (Drafts §3) — the one way past ExcludesDrafts.
     *
     * Always narrowed to an author, because a draft is private to whoever wrote it (§4) and an
     * unfiltered draft query is a leak rather than a listing. The tenant scope still applies
     * on top, so this reads one person's drafts inside the active workspace.
     */
    public function scopeDrafts(Builder $query, int $authorId): Builder
    {
        return $query->withoutGlobalScope(ExcludesDrafts::class)
            ->where('is_draft', true)
            ->where('created_by', $authorId);
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function isDraft(): bool
    {
        return (bool) $this->is_draft;
    }
}
