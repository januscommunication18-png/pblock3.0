<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * A pending teammate invitation scoped to a workspace (spec §6/§8).
 *
 * TENANT-SCOPED (CLAUDE.md §7): the BelongsToTenant trait adds the `tenant_id` foreign
 * key and a global TenantScope, so once tenancy is initialized to a workspace all
 * invitation queries are automatically confined to it, and new rows inherit the current
 * tenant id. Cross-tenant lookups (e.g. resolving a raw token on accept) must opt out
 * with the `withoutTenancy` builder macro.
 */
class WorkspaceInvitation extends Model
{
    use BelongsToTenant;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REVOKED = 'revoked';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'tenant_id',
        'email',
        'role',
        'inviter_user_id',
        'token',
        'status',
        'expires_at',
        'accepted_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    /** Domain-friendly alias for the tenant relationship. */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'tenant_id');
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inviter_user_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
