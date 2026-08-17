<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * Somebody outside the workspace who may read ONE collection
 * (docs/features/wiki-external-guests.md). TENANT-SCOPED.
 *
 * Not a `User`: no account, no workspace, no membership, and no presence in any picker, mention
 * list or notification. Removing the row is the whole of revocation.
 */
class WikiCollectionGuest extends Model
{
    use BelongsToTenant;

    public const LOGIN_MAGIC_LINK = 'magic_link';

    protected $attributes = [
        'login_method' => self::LOGIN_MAGIC_LINK,
    ];

    protected $fillable = [
        'tenant_id',
        'wiki_collection_id',
        'name',
        'email',
        'login_method',
        'token',
        'invited_by',
        'invited_at',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'invited_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /** The login methods this application can actually perform today. */
    public static function loginMethods(): array
    {
        return [self::LOGIN_MAGIC_LINK];
    }

    /** A fresh raw token for a magic link. Only its hash is ever persisted. */
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
     * Resolve a guest from a raw token, across every workspace.
     *
     * `withoutTenancy` deliberately: the person opening the link is not inside any workspace —
     * they do not have an account at all — so the tenant scope would hide the very row that says
     * which workspace they belong in. The same thing WorkspaceInvitation::findByToken() does.
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

    public function collection(): BelongsTo
    {
        return $this->belongsTo(WikiCollection::class, 'wiki_collection_id');
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /** Domain-friendly alias for the tenant relationship. */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'tenant_id');
    }

    /**
     * @return array<string, mixed>
     *
     * The token is absent, and stays absent. It is a credential; it belongs in one email and
     * nowhere else — not in a payload the browser can read, and not on any screen.
     */
    public function toCard(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'login_method' => $this->login_method,
            'invited_at' => $this->invited_at?->diffForHumans(),
            'last_seen' => $this->last_seen_at?->diffForHumans(),
            'opened' => $this->last_seen_at !== null,
        ];
    }
}
