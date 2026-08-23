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
        // The agent's personal sign-off, appended to Help Center replies (P74).
        'signature',
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
    /**
     * The signature as HTML, or an empty string (P74).
     *
     * Stored as plain text and converted here, once, so every caller renders it the same way.
     * `fromPlainText` escapes the content and turns line breaks into markup, which is what makes
     * a signature containing `<b>` show those characters rather than emboldening the rest of the
     * email — the agent typed text, not markup, and the column says so.
     */
    public function signatureHtml(): string
    {
        $text = trim((string) $this->signature);

        return $text === '' ? '' : (string) app(\App\Services\RichTextSanitizer::class)->fromPlainText($text);
    }

    /** Is there a personal signature worth appending? */
    public function hasSignature(): bool
    {
        return trim((string) $this->signature) !== '';
    }

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

    /**
     * The fallback avatar background — this person's own colour.
     *
     * The PHP twin of `PB.avatarColor()`: same palette (`projects.avatar_colors`), same rule,
     * same key (the user id), so a badge rendered by Blade in the topbar and one rendered by
     * Vue in a work item row are the same colour for the same person. A test pins the two
     * implementations together.
     */
    public function avatarColor(): string
    {
        $palette = array_values(config('projects.avatar_colors'));
        $key = (string) $this->id;

        // A numeric id walks the palette; see PB.avatarColor for why it is not hashed.
        if (ctype_digit($key)) {
            return $palette[(int) $key % count($palette)];
        }

        $hash = 5381;
        for ($i = 0; $i < strlen($key); $i++) {
            // & 0xFFFFFFFF keeps this inside 32 bits, which is where JavaScript's >>> 0 leaves
            // it — without that, PHP's wider integers diverge from the browser after a few
            // characters and the same person gets two different colours.
            $hash = (($hash * 33) ^ ord($key[$i])) & 0xFFFFFFFF;
        }

        return $palette[$hash % count($palette)];
    }

    public function initial(): string
    {
        $source = $this->displayName() ?: $this->email;

        return strtoupper(mb_substr(trim((string) $source), 0, 1)) ?: '?';
    }
}
