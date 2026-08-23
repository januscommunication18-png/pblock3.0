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

    /**
     * The workspace hierarchy, highest first
     * (docs/features/ProjectBlock_3_Workspace_Roles_Ownership_Gap_Remediation §4.2).
     *
     * A comparable RANK rather than a set of special cases: "an admin may only manage members
     * below admin", "nobody may promote to owner but an owner" and "you cannot act on somebody
     * who outranks you" are then one comparison instead of four rules that can disagree.
     *
     * `manager` and `viewer` are ranked alongside `member` because they carry no workspace
     * privileges of their own (see config/workspace.php) — they exist for project-level meaning,
     * and giving them their own rank here would invent a hierarchy the product does not have.
     */
    public const RANKS = [
        'owner' => 40,
        'admin' => 30,
        'manager' => 20,
        'member' => 20,
        'viewer' => 20,
        'guest' => 10,
    ];

    public const STATUS_ACTIVE = 'active';

    /**
     * Tenant access revoked by the Back Office (backoffice-clients.md, § "Client Actions").
     *
     * A new value in an existing vocabulary, so no migration: `status` is a string column. It is
     * the counterpart to disabling a CLIENT — that closes every tenant to somebody, this closes
     * one, which is the distinction the requirement draws to stop an administrator "accidentally
     * disabling all of a customer's tenant access".
     *
     * Existing queries that filter on `STATUS_ACTIVE` already exclude it, so a disabled
     * membership drops out of member lists and access checks without those callers changing.
     */
    public const STATUS_DISABLED = 'disabled';

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

    /** Where this membership sits in the hierarchy. Unknown roles rank lowest, never highest. */
    public function rank(): int
    {
        return self::RANKS[$this->role] ?? 0;
    }

    /** Strictly above — equals do NOT outrank each other, so an admin cannot act on an admin. */
    public function outranks(self $other): bool
    {
        return $this->rank() > $other->rank();
    }

    /** The rank a role name carries, for checks made before a membership exists. */
    public static function rankOf(?string $role): int
    {
        return self::RANKS[$role] ?? 0;
    }

    /**
     * Is this the only owner left?
     *
     * The safeguard behind "the last owner cannot be removed, demoted, or leave" (§12): a
     * workspace with no owner has no one who can delete it, transfer it, or promote anybody —
     * an unrecoverable state reachable by one careless click.
     */
    public function isLastOwner(): bool
    {
        if (! $this->isOwner()) {
            return false;
        }

        return static::query()
            ->where('workspace_id', $this->workspace_id)
            ->where('role', self::ROLE_OWNER)
            ->where('status', self::STATUS_ACTIVE)
            ->where('id', '!=', $this->id)
            ->doesntExist();
    }
}
