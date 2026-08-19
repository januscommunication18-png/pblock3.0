<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * What should happen to an invited coworker once they accept (FR-1.5). TENANT-SCOPED.
 *
 * Not an invitation of its own (decision H11) — it hangs off the `workspace_invitations` row
 * that carries the token, the email and the acceptance screen. This row is the Help Desk half
 * of that one invitation: the role they will hold and the inboxes they will be given.
 */
class HelpDeskInvite extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'help_desk_id',
        'workspace_invitation_id',
        'email',
        'role',
        'inbox_ids',
        'invited_by',
        'redeemed_at',
    ];

    protected function casts(): array
    {
        return [
            'inbox_ids' => 'array',
            'redeemed_at' => 'datetime',
        ];
    }

    public function helpDesk(): BelongsTo
    {
        return $this->belongsTo(HelpDesk::class);
    }

    public function invitation(): BelongsTo
    {
        return $this->belongsTo(WorkspaceInvitation::class, 'workspace_invitation_id');
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function isPending(): bool
    {
        return $this->redeemed_at === null;
    }
}
