<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * Project membership (Phase 4, spec §4.3). TENANT-SCOPED (BelongsToTenant).
 *
 * Presence of a row grants a user access to a PRIVATE project (PRJ-031). The creator is
 * seeded as `admin`; the assigned lead as `member`.
 */
class ProjectMember extends Model
{
    use BelongsToTenant;

    /** Full administrative control inside this project (§3). */
    public const ROLE_ADMIN = 'admin';

    /** Normal active project team member — creates and updates work (§3). */
    public const ROLE_CONTRIBUTOR = 'contributor';

    /** Reviewer / stakeholder: reads and comments, cannot change work (§3). */
    public const ROLE_COMMENTER = 'commenter';

    /** Restricted participant, limited to explicitly permitted content (§3). */
    public const ROLE_GUEST = 'guest';

    protected $fillable = [
        'tenant_id',
        'project_id',
        'user_id',
        'added_by',
        'role',
    ];

    /**
     * This user's role in this project, or null if they are not a member.
     *
     * The entry point for §16's layer 2 — both ProjectPolicy and WorkItemPolicy resolve
     * project permissions through here, so the two-layer model has one implementation.
     */
    public static function roleFor(int $userId, int $projectId): ?string
    {
        return static::query()
            ->where('project_id', $projectId)
            ->where('user_id', $userId)
            ->value('role');
    }

    /** May this project role create and edit work items (§34)? */
    public static function contributes(?string $role): bool
    {
        return $role !== null && in_array($role, config('projects.contributor_roles'), true);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Who added this person to the project (§25) — shown as "Added By" on the list. */
    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    /** Project admin — the role that may manage this project's settings and members. */
    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }
}
