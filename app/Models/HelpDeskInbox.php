<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * An inbox inside a Help Desk (docs/features/help-desk.md, FR-1.7). TENANT-SCOPED.
 *
 * A name and nothing else in Phase 1 — that is all inbox-level access needs. The email address,
 * channel and routing rules that make an inbox receive anything are Phase 2, and they extend
 * this row rather than replace it.
 */
class HelpDeskInbox extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'help_desk_id',
        'name',
        'created_by',
    ];

    public function helpDesk(): BelongsTo
    {
        return $this->belongsTo(HelpDesk::class);
    }

    /** The members explicitly given this inbox. Admins and Managers reach it without a row. */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(HelpDeskMember::class, 'help_desk_member_inboxes')
            ->withTimestamps();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
