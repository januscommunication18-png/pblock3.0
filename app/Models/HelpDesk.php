<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * A workspace's Help Desk (docs/features/help-desk.md). TENANT-SCOPED.
 *
 * One row per workspace, created the first time somebody opens the app — see
 * HelpDeskProvisioner. It exists so inboxes and members have something to belong to, and so
 * "the workspace has Help Desk switched on" (a settings flag) stays a different fact from
 * "the workspace has a Help Desk set up" (this row).
 */
class HelpDesk extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'name',
        'created_by',
    ];

    public function inboxes(): HasMany
    {
        return $this->hasMany(HelpDeskInbox::class);
    }

    /** Active and inactive members both — the member list has to show who is deactivated. */
    public function members(): HasMany
    {
        return $this->hasMany(HelpDeskMember::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
