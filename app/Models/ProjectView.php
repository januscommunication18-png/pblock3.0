<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * A saved spreadsheet-style view of a project's data (Views §5, §13).
 *
 * TENANT-SCOPED (CLAUDE.md §7): BelongsToTenant confines every query to the active workspace;
 * project scoping is applied explicitly on top, as everywhere else in the project workspace.
 *
 * A View owns nothing but configuration. The rows it shows are ordinary work items read
 * through the ordinary policy, which is what keeps §11.3's promise that a View can never widen
 * access — there is no data here to leak, only a description of which columns to render.
 */
class ProjectView extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'project_views';

    /** §6.2 — the only dataset in Phase 1; §30 keeps the door open for Epics/Modules/Cycles. */
    public const DATASET_WORK_ITEMS = 'work_items';

    /** §6.3 — visible to the owner alone… */
    public const VISIBILITY_PRIVATE = 'private';

    /** …or to permitted project members. */
    public const VISIBILITY_PROJECT = 'project';

    protected $fillable = [
        'tenant_id',
        'project_id',
        'name',
        'dataset_type',
        'visibility',
        'owner_user_id',
        'density',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * The View's columns, already in render order (§8.3).
     *
     * §8.3: Fixed before Scroll "regardless of internal database order" — expressed here rather
     * than left to each caller, because a View rendered in a different order than it was
     * configured in is the whole feature going wrong.
     *
     * ASCENDING, and the direction is load-bearing: 'fixed' < 'scroll' alphabetically, so
     * ascending is what puts the pinned columns first. Descending renders every Scroll column
     * ahead of every Fixed one, which does not merely reorder the grid — the frozen boundary
     * is derived from the Fixed COUNT (§7.2), so it lands after the first N scroll columns and
     * pins the wrong ones.
     */
    public function columns(): HasMany
    {
        return $this->hasMany(ProjectViewColumn::class)
            ->orderBy('position_type')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function favorites(): HasMany
    {
        return $this->hasMany(ProjectViewFavorite::class);
    }

    public function isPrivate(): bool
    {
        return $this->visibility === self::VISIBILITY_PRIVATE;
    }

    public function isOwnedBy(?User $user): bool
    {
        return $user !== null && (int) $this->owner_user_id === (int) $user->id;
    }

    /** @param  Builder<ProjectView>  $query */
    public function scopeForProject(Builder $query, int $projectId): Builder
    {
        return $query->where('project_id', $projectId);
    }

    /**
     * The Views this user may see in the listing (§5.1).
     *
     * Project Views for everyone permitted on the project, plus the caller's OWN private ones.
     * Deliberately not "private views the caller can manage": §14.4 lets an admin delete an
     * abandoned private View, but deleting is not reading, and a private View that a workspace
     * admin could open would not be private.
     *
     * @param  Builder<ProjectView>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('visibility', self::VISIBILITY_PROJECT)
            ->orWhere('owner_user_id', $user->id));
    }
}
