<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * A project page (Pages §8) — project documentation: requirements, notes, decisions.
 *
 * TENANT-SCOPED (CLAUDE.md §7): BelongsToTenant confines every query to the active workspace
 * and stamps `tenant_id` on insert. Project scoping is applied explicitly on top (§4.6).
 *
 * §14 wants these pages to join the Wiki later without their content being migrated or
 * recreated. That is why the content lives in one column on one row with a parent pointer:
 * a hierarchy can be re-parented, and a page can be published elsewhere, without the document
 * itself ever moving.
 */
class ProjectPage extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'project_pages';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    protected $fillable = [
        'tenant_id',
        'project_id',
        'title',
        'content',
        'status',
        'parent_id',
        'archived_at',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return ['archived_at' => 'datetime'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** §9: the parent page, when applicable. */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** Pages of one project — always applied, since a workspace holds many projects. */
    public function scopeForProject(Builder $query, int $projectId): Builder
    {
        return $query->where('project_id', $projectId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    /** The configured label and colour for §9's page status. */
    public function statusMeta(): array
    {
        $statuses = config('projects.page_statuses');

        return $statuses[$this->status] ?? $statuses[self::STATUS_DRAFT];
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }
}
