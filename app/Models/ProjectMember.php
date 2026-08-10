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

    public const ROLE_ADMIN = 'admin';

    public const ROLE_MEMBER = 'member';

    protected $fillable = [
        'tenant_id',
        'project_id',
        'user_id',
        'role',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
