<?php

namespace App\Models;

use Database\Factories\WorkspaceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
