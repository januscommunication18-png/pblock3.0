<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Back Office security event (docs/features/backoffice-auth.md, §11). CENTRAL.
 *
 * Written by `BackofficeAudit`, which is the only thing that should construct these — the
 * service captures IP and user agent from the request so no caller has to remember to.
 */
class BackofficeAuditLog extends Model
{
    /** An audit row is a statement about a moment and is never edited. */
    public const UPDATED_AT = null;

    // The requirement's own vocabulary (§11), verbatim, so a log line can be grepped for using
    // the words the specification uses.
    public const CODE_REQUESTED = 'BACKOFFICE_SECURITY_CODE_REQUESTED';

    public const CODE_SENT = 'BACKOFFICE_SECURITY_CODE_SENT';

    public const CODE_FAILED = 'BACKOFFICE_SECURITY_CODE_FAILED';

    public const CODE_VERIFIED = 'BACKOFFICE_SECURITY_CODE_VERIFIED';

    public const LOGIN_SUCCESS = 'BACKOFFICE_LOGIN_SUCCESS';

    public const LOGIN_FAILED = 'BACKOFFICE_LOGIN_FAILED';

    public const LOGOUT = 'BACKOFFICE_LOGOUT';

    public const PASSWORD_RESET_REQUESTED = 'BACKOFFICE_PASSWORD_RESET_REQUESTED';

    public const PASSWORD_CHANGED = 'BACKOFFICE_PASSWORD_CHANGED';

    public const USER_CREATED = 'BACKOFFICE_USER_CREATED';

    public const USER_DISABLED = 'BACKOFFICE_USER_DISABLED';

    public const ROLE_CHANGED = 'BACKOFFICE_ROLE_CHANGED';

    public const SUPER_ADMIN_CREATED = 'BACKOFFICE_SUPER_ADMIN_CREATED';

    /*
     * Client actions (docs/features/backoffice-clients.md, §24), the requirement's own
     * vocabulary. On the AUDIT log as well as the client's activity feed, because the two answer
     * different questions (BC-D5): this one is "what did an administrator do", with an IP and a
     * user agent attached.
     */
    public const CLIENT_VIEWED = 'CLIENT_VIEWED';

    public const CLIENT_PASSWORD_RESET_REQUESTED = 'CLIENT_PASSWORD_RESET_REQUESTED';

    public const CLIENT_DISABLED = 'CLIENT_DISABLED';

    public const CLIENT_ENABLED = 'CLIENT_ENABLED';

    public const CLIENT_DELETE_REQUESTED = 'CLIENT_DELETE_REQUESTED';

    public const CLIENT_RESTORED = 'CLIENT_RESTORED';

    public const CLIENT_DELETED = 'CLIENT_DELETED';

    /** A change scoped to ONE of the client's tenants, never all of them. */
    public const CLIENT_TENANT_CHANGED = 'CLIENT_TENANT_CHANGED';

    protected $fillable = [
        'backoffice_user_id', 'email', 'action', 'ip', 'user_agent', 'succeeded', 'meta',
    ];

    protected function casts(): array
    {
        return ['succeeded' => 'boolean', 'meta' => 'array', 'created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(BackofficeUser::class, 'backoffice_user_id');
    }

    public function scopeFailures(Builder $query): Builder
    {
        return $query->where('succeeded', false);
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'email' => $this->email,
            'user' => $this->user?->name,
            'ip' => $this->ip,
            'user_agent' => $this->user_agent,
            'succeeded' => $this->succeeded,
            'meta' => $this->meta,
            'at' => $this->created_at?->format('M j, Y g:i:s A'),
        ];
    }
}
