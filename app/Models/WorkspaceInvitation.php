<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * A pending teammate invitation scoped to a workspace (spec §6/§8, invite spec §11).
 *
 * TENANT-SCOPED (CLAUDE.md §7): the BelongsToTenant trait adds the `tenant_id` foreign
 * key and a global TenantScope, so once tenancy is initialized to a workspace all
 * invitation queries are automatically confined to it, and new rows inherit the current
 * tenant id. Cross-tenant lookups (resolving a raw token on accept, finding the invitation a
 * signed-in user is here for) must opt out with the `withoutTenancy` builder macro — hence
 * `findByToken()` / `pendingFor()` below rather than ad-hoc queries in controllers.
 *
 * The `token` column holds the SHA-256 hash of the raw token; the raw value exists only long
 * enough to build the invitation link and is never stored or logged (invite spec §13/§79).
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
        'user_id',
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

    /** A fresh raw token for an invitation link. Only its hash is ever persisted. */
    public static function newToken(): string
    {
        return Str::random(64);
    }

    /** The stored form of a raw token. Deterministic, so the link can be looked up. */
    public static function hashToken(string $raw): string
    {
        return hash('sha256', $raw);
    }

    /**
     * Resolve an invitation from a raw token, across every workspace.
     *
     * The lookup deliberately runs `withoutTenancy`: the person opening the link is not (yet)
     * inside any workspace, so the tenant scope would hide the very row we need.
     */
    public static function findByToken(string $raw): ?self
    {
        if ($raw === '') {
            return null;
        }

        return static::query()->withoutTenancy()
            ->where('token', static::hashToken($raw))
            ->first();
    }

    /**
     * Pending invitations for an email address across all workspaces (invite spec §32/§83).
     * Newest first — if someone was invited to several workspaces, the most recent invitation
     * is the one they are most likely acting on.
     */
    public static function pendingFor(string $email): Builder
    {
        return static::query()->withoutTenancy()
            ->where('email', strtolower(trim($email)))
            ->where('status', self::STATUS_PENDING)
            ->latest('id');
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

    /** The user this invitation resolved to, once their identity is known (§32). */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** Pending *and* still inside its validity window — the only acceptable state (§18). */
    public function isAcceptable(): bool
    {
        return $this->isPending() && ! $this->isExpired();
    }

    /**
     * Flip a lapsed pending invitation to `expired` in place (§14).
     *
     * Expiry is time-based, so without this an untouched row would sit at `pending` forever
     * and keep occupying a seat and a row on the Members screen. Called on read; there is no
     * scheduled sweep.
     */
    public function markExpiredIfLapsed(): bool
    {
        if (! $this->isPending() || ! $this->isExpired()) {
            return false;
        }

        $this->forceFill(['status' => self::STATUS_EXPIRED])->save();

        return true;
    }
}
