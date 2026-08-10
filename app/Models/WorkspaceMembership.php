<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Links a central User to a Workspace (tenant) with a role (spec §8).
 *
 * Central, cross-tenant table (CLAUDE.md §18 D6): this is queried without a tenancy
 * context to list a user's workspaces and to authorize access before tenancy is
 * initialized, so it deliberately does NOT use the BelongsToTenant global scope.
 */
class WorkspaceMembership extends Model
{
    public const ROLE_OWNER = 'owner';

    public const STATUS_ACTIVE = 'active';

    protected $fillable = [
        'workspace_id',
        'user_id',
        'role',
        'status',
        'invited_at',
        'joined_at',
    ];

    protected function casts(): array
    {
        return [
            'invited_at' => 'datetime',
            'joined_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'workspace_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isOwner(): bool
    {
        return $this->role === self::ROLE_OWNER;
    }
}
