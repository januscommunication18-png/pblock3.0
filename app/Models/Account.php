<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The owning entity above a workspace — the requirement's **Tenant**
 * (docs/features/tenant-workspace-ownership.md).
 *
 * Named Account rather than Tenant because `tenants` is already the workspace table in this
 * codebase: a Workspace IS the stancl tenant (CLAUDE.md §18 D5). Same concept, free name.
 *
 * One per client who owns workspaces:
 *
 *     Account (owner: Mike)
 *       ├── Mike Workspace
 *       ├── Marketing Workspace
 *       └── Operations Workspace
 *
 * It is created LAZILY — the first time somebody creates a workspace, not when they sign up
 * (§20/§21). Somebody who only ever accepted an invitation owns nothing and therefore has no
 * account, which is the honest answer rather than an empty row waiting for them.
 *
 * CENTRAL: never tenant-scoped, because it sits above tenancy and is read before any tenant is
 * initialized.
 *
 * Ownership is NOT access. Owning the account a workspace belongs to grants nothing on its own;
 * every workspace is entered through an active `workspace_memberships` row and nothing else
 * (docs/features/workspace-access-control.md). The account answers "whose workspaces are
 * these?", never "who may open this one?".
 */
class Account extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = ['code', 'owner_user_id', 'name', 'status'];

    /** The person who owns this account, or null once that user has been deleted. */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /** Every workspace under this account (§3). */
    public function workspaces(): HasMany
    {
        return $this->hasMany(Workspace::class, 'account_id');
    }

    public function isOwnedBy(?User $user): bool
    {
        return $user !== null
            && $this->owner_user_id !== null
            && (int) $this->owner_user_id === (int) $user->id;
    }
}
