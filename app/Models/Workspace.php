<?php

namespace App\Models;

use Database\Factories\WorkspaceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

/**
 * A Workspace is the tenant in this single-database multi-tenant app (spec §8).
 *
 * It extends the stancl/tenancy Tenant model, so it carries a durable UUID `id`
 * (the internal tenant key) and participates in tenancy initialization. Real columns
 * are declared in getCustomColumns() so stancl's VirtualColumn does not fold them into
 * the `data` JSON overflow column.
 */
class Workspace extends BaseTenant
{
    use HasFactory;

    public const VIEW_AGILE = 'agile';

    public const VIEW_CLASSIC = 'classic';

    /**
     * Columns that are real database columns (everything else overflows into `data`).
     */
    public static function getCustomColumns(): array
    {
        return [
            'id',
            'name',
            'slug',
            /*
             * The customer-facing subdomain (P72). Declared HERE for the reason the note below
             * gives: left off this list, VirtualColumn folds it into `data` instead of the real
             * column the migration added — and the unique index that is supposed to guarantee
             * one tenant per host would be guarding a column nothing ever writes.
             */
            'subdomain',
            'company_size',
            'timezone',
            'logo_url',
            'view_type',
            'status',
            'created_by',
            // Counter behind the workspace-unique work item ID; allocated by WorkItemCreator.
            'work_item_sequence',
            // Language & Time (Account §2). Declared here for the reason the docblock gives:
            // left off this list, VirtualColumn would quietly fold them into `data` instead of
            // the real columns the migration just added, and the columns would stay null.
            'language',
            'first_day_of_week',
            'weekend_days',
        ];
    }

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'work_item_sequence' => 'integer',
            // ISO-8601 day numbers, 1 = Monday … 7 = Sunday.
            'weekend_days' => 'array',
            'first_day_of_week' => 'integer',
        ];
    }

    /** Memberships are central plumbing (not tenant-scoped) — see WorkspaceMembership. */
    /**
     * This workspace's settings row, readable with NO tenancy context (Back Office, §10).
     *
     * `workspaceSettings`, NOT `settings`: `Workspace` extends stancl's Tenant, which folds any
     * attribute that is not in `getCustomColumns()` into the `data` JSON column. A relation
     * called `settings` would collide with that lookup — the same class of bug as naming a
     * relation after an existing column. The longer name has no such twin.
     *
     * `withoutGlobalScopes()` is load-bearing: `WorkspaceSettings` uses `BelongsToTenant`, whose
     * global scope confines every query to the ACTIVE tenant. The Back Office runs with none, so
     * without this the relation would resolve against nothing for every workspace.
     */
    public function workspaceSettings(): HasOne
    {
        return $this->hasOne(WorkspaceSettings::class, 'tenant_id')->withoutGlobalScopes();
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(WorkspaceMembership::class, 'workspace_id');
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(WorkspaceInvitation::class, 'tenant_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Uppercase initial for the workspace avatar tile. */
    public function initial(): string
    {
        return strtoupper(mb_substr(trim((string) $this->name), 0, 1)) ?: 'W';
    }

    protected static function newFactory(): WorkspaceFactory
    {
        return WorkspaceFactory::new();
    }
}
