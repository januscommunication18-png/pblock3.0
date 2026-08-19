<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * Somebody in a Space's support group (docs/features/help-center.md, P2 §7, §8).
 * TENANT-SCOPED.
 *
 * `user_id` is NULLABLE on purpose. P2 §8 allows adding a coworker by an email address that is
 * not yet a workspace member, in which case the row exists before the user does and the
 * invitation is what connects them later. Until then `email` identifies the person — which is
 * why the unique key is on the email rather than on the user id.
 */
class HelpCenterSpaceMember extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'help_center_space_id',
        'user_id',
        'workspace_invitation_id',
        'email',
        'invited_role',
        'department_groups',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'department_groups' => 'array',
        ];
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(HelpCenterSpace::class, 'help_center_space_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invitation(): BelongsTo
    {
        return $this->belongsTo(WorkspaceInvitation::class, 'workspace_invitation_id');
    }

    /** Normalized the same way Inbox addresses are, so one person is never two rows. */
    public function setEmailAttribute(?string $value): void
    {
        $this->attributes['email'] = $value === null ? null : mb_strtolower(trim($value));
    }

    /** Still waiting on an invitation to be accepted (P2 §8). */
    public function isPending(): bool
    {
        return $this->user_id === null;
    }

    /** @return array<int, string> */
    public function groupList(): array
    {
        return array_values(array_filter((array) $this->department_groups));
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'email' => $this->email,
            'name' => $this->relationLoaded('user') && $this->user
                ? $this->user->displayName()
                : $this->email,
            'pending' => $this->isPending(),
            'invited_role' => $this->invited_role,
            'department_groups' => $this->groupList(),
        ];
    }
}
