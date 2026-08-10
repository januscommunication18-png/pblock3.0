<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Central (non-tenant) user. Phase 1 auth is pre-workspace — see spec D-A1.
 * Passwords are optional and stored in user_password_credentials (D-A2).
 */
class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'full_name',
        'email',
        'email_verified_at',
        'avatar_url',
        'status',
        'locale',
        'timezone',
        'marketing_opt_in',
        'terms_accepted_at',
        'current_workspace_id',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'marketing_opt_in' => 'boolean',
            'password' => 'hashed',
        ];
    }

    public function identities(): HasMany
    {
        return $this->hasMany(UserIdentity::class);
    }

    public function passwordCredential(): HasOne
    {
        return $this->hasOne(UserPasswordCredential::class);
    }

    public function onboardingProfile(): HasOne
    {
        return $this->hasOne(OnboardingProfile::class);
    }

    /** Workspace memberships (central, cross-tenant) — powers the workspace switcher. */
    public function memberships(): HasMany
    {
        return $this->hasMany(WorkspaceMembership::class);
    }

    /** Workspaces this user belongs to, through active memberships. */
    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_memberships', 'user_id', 'workspace_id')
            ->withPivot(['role', 'status'])
            ->withTimestamps();
    }

    public function currentWorkspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'current_workspace_id');
    }

    public function hasPassword(): bool
    {
        return $this->passwordCredential()->exists();
    }

    /** Display name fallback for the header chip. */
    public function displayName(): string
    {
        return $this->full_name ?: strtok((string) $this->email, '@');
    }

    /** Uppercase initial for the avatar placeholder. */
    public function initial(): string
    {
        $source = $this->full_name ?: $this->email;

        return strtoupper(mb_substr(trim((string) $source), 0, 1)) ?: '?';
    }
}
