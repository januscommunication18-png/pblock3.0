<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
    use BelongsToTenant;

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
        'cover_url',
        'timezone',
        'status',
        'created_by',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
        ];
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
