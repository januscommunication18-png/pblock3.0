<?php

namespace App\Models;

use App\Notifications\BackofficePasswordReset;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use RuntimeException;

/**
 * A Back Office administrator (docs/features/backoffice-auth.md, §7–§8). CENTRAL.
 *
 * Deliberately NOT `App\Models\User`, and deliberately not a subclass of it (BO-D1). The
 * boundary §9 asks for is expressed in the schema: the `backoffice` guard's provider resolves
 * this model and only this model, so a customer account cannot authenticate into the Back
 * Office however the rest of the application is wired.
 *
 * `password_hash`, not `password`. The column is named for what it holds, and `getAuthPassword()`
 * below is what tells the guard where to look — the same shape `UserPasswordCredential` uses on
 * the customer side.
 */
class BackofficeUser extends Authenticatable
{
    use Notifiable;

    public const ROLE_SUPER_ADMIN = 'super_admin';

    public const ROLE_ADMIN = 'admin';

    public const ROLE_READONLY = 'readonly';

    /** @var array<int, string> */
    public const ROLES = [self::ROLE_SUPER_ADMIN, self::ROLE_ADMIN, self::ROLE_READONLY];

    protected $fillable = [
        'name', 'email', 'password_hash', 'password_set_at',
        'role', 'is_active', 'last_login_at', 'last_login_ip', 'created_by',
    ];

    /** The hash never leaves the model, whatever a caller serialises. */
    protected $hidden = ['password_hash', 'remember_token'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'password_set_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    /** Where the guard looks for the hash, since the column is not called `password`. */
    public function getAuthPassword(): string
    {
        return (string) $this->password_hash;
    }

    /**
     * The reset link points at the BACK OFFICE, not the customer app.
     *
     * Laravel's default notification builds its URL from the `password.reset` route, which
     * belongs to the customer application — an administrator following it would land on the
     * wrong form with a token that cannot resolve there.
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new BackofficePasswordReset($token));
    }

    /** Normalised on the way in, like every other address in this codebase — lookups depend on it. */
    public function setEmailAttribute(?string $value): void
    {
        $this->attributes['email'] = $value === null ? null : mb_strtolower(trim($value));
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeSuperAdmins(Builder $query): Builder
    {
        return $query->where('role', self::ROLE_SUPER_ADMIN);
    }

    public function creator()
    {
        return $this->belongsTo(self::class, 'created_by');
    }

    /**
     * May this account complete a sign-in at all?
     *
     * Active AND holding a password. A seeded Super Admin has no password yet (BO-D5) and must
     * go through the reset flow first — `Auth::attempt` would refuse an empty hash anyway, but
     * saying so here means the gate at `/backoffice` can behave identically for both and leak
     * nothing about which state an address is in.
     */
    public function canSignIn(): bool
    {
        return $this->is_active && trim((string) $this->password_hash) !== '';
    }

    /**
     * May this address get as far as the code screen (§2)?
     *
     * An account with no password yet still passes: they need the code to reach the login screen,
     * where "Forgot password" is the way in. Refusing them here would leave a seeded Super Admin
     * with no route to their own account.
     */
    public function mayVerify(): bool
    {
        return $this->is_active;
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === self::ROLE_SUPER_ADMIN && $this->is_active;
    }

    /** Managing Back Office users and roles is the Super Admin's alone (§8). */
    public function canManageAdmins(): bool
    {
        return $this->isSuperAdmin();
    }

    public function canWrite(): bool
    {
        return $this->is_active && $this->role !== self::ROLE_READONLY;
    }

    public static function roleLabel(string $role): string
    {
        return match ($role) {
            self::ROLE_SUPER_ADMIN => 'Super Admin',
            self::ROLE_ADMIN => 'Admin',
            self::ROLE_READONLY => 'Read-only',
            default => $role,
        };
    }

    /**
     * Is this the last thing standing between the platform and nobody being able to administer
     * it? (§8)
     *
     * Counted EXCLUDING this row rather than counting all and comparing to 1: the question is
     * "would any active Super Admin remain without me?", and asking it that way cannot be got
     * wrong by an off-by-one.
     */
    public function isLastActiveSuperAdmin(): bool
    {
        if (! $this->isSuperAdmin()) {
            return false;
        }

        return ! self::query()
            ->active()
            ->superAdmins()
            ->whereKeyNot($this->getKey())
            ->exists();
    }

    /**
     * The one invariant that can lock everybody out of the platform permanently.
     *
     * Enforced HERE and not in a controller (BO-D6). A controller check protects the one route
     * somebody remembered to put it on; a model check protects every route, every console
     * command and every future screen. It throws rather than returning false because there is no
     * sensible way to continue — the caller asked for something that must not happen.
     */
    public function guardLastSuperAdmin(string $action): void
    {
        if ($this->isLastActiveSuperAdmin()) {
            throw new RuntimeException(
                'This is the only active Super Admin. Create another one before you '.$action.' this account.',
            );
        }
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'role_label' => self::roleLabel((string) $this->role),
            'is_active' => $this->is_active,
            'has_password' => trim((string) $this->password_hash) !== '',
            'last_login' => $this->last_login_at?->format('M j, Y g:i A'),
            'is_last_super_admin' => $this->isLastActiveSuperAdmin(),
        ];
    }
}
