<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
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
        'first_name',
        'last_name',
        'full_name',
        'display_name',
        'email',
        'email_verified_at',
        'avatar_url',
        'cover_url',
        'cover_gradient',
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

    /**
     * What to call this person, everywhere.
     *
     * `display_name` first: a user who has said what they want to be called has said it about
     * every screen, not just their profile. Then their name, then the local part of their
     * email — which is not a name, but it is theirs and it is better than an empty chip.
     */
    public function displayName(): string
    {
        return $this->display_name ?: ($this->full_name ?: strtok((string) $this->email, '@'));
    }

    /**
     * First and last as one string.
     *
     * The form collects two fields; the rest of the app reads `full_name`. Rather than teach
     * every reader about the split, the split is joined here and stored on save — so this is
     * the only place that knows the name has parts.
     */
    public function composeFullName(): ?string
    {
        $name = trim(implode(' ', array_filter([$this->first_name, $this->last_name])));

        return $name === '' ? null : $name;
    }

    /** Uppercase initial for the avatar placeholder. */
    /**
     * The avatar as a URL, wherever the row happens to hold it.
     *
     * Profile images moved to the PRIVATE disk (Account §1), so `avatar_url` stores a disk
     * path — `account/7/xyz.png` — and is served through the `account.image` route. Every
     * other screen in the application reads this attribute straight into an `<img src>`: work
     * item rows, member lists, comments, the topbar. A bare path there resolves against
     * whatever page it is on, so every one of them silently showed a broken image.
     *
     * Resolved here rather than at each of those call sites, because "where is this file
     * actually served from" is the model's business and there are a dozen readers. The raw
     * column is still what ProfileController stores, deletes and streams — it reaches it with
     * `getRawOriginal`, which is the one place that wants the path rather than the URL.
     *
     * Values that are already a URL are passed through: avatars captured from an OAuth
     * provider are the remote URL, and the pre-Account uploads wrote an absolute one.
     */
    protected function avatarUrl(): Attribute
    {
        return Attribute::get(fn (?string $value) => $this->imageUrl($value, 'avatar'));
    }

    protected function coverUrl(): Attribute
    {
        return Attribute::get(fn (?string $value) => $this->imageUrl($value, 'cover'));
    }

    /**
     * Stamped with a hash of the stored path: the route names the SLOT, not the file, so
     * without it a replaced image keeps showing from cache under an identical URL.
     */
    private function imageUrl(?string $value, string $kind): ?string
    {
        if (! $value) {
            return null;
        }

        // Already servable as-is — an OAuth avatar, or an upload from before this moved.
        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://') || str_starts_with($value, '/')) {
            return $value;
        }

        return route('account.image', ['user' => $this->id, 'kind' => $kind])
            .'?v='.substr(md5($value), 0, 10);
    }

    public function initial(): string
    {
        $source = $this->displayName() ?: $this->email;

        return strtoupper(mb_substr(trim((string) $source), 0, 1)) ?: '?';
    }
}
