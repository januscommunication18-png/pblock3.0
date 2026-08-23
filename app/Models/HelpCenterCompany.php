<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * The organisation a customer belongs to (docs/features/help-center.md, P75 §7). TENANT-SCOPED.
 *
 * WORKSPACE-scoped like `HelpCenterCustomer`, not Space-scoped (HC-D51): one Acme per workspace,
 * whichever Space the mail reached. The Space's own questions about a company are its custom
 * FIELDS, which stay Space-scoped.
 *
 * Identity is `domain` first and `external_id` second, both unique per tenant in the schema —
 * enforced there rather than only in the matcher, because two workers can ingest two emails from
 * the same new domain at the same moment and both find nothing.
 */
class HelpCenterCompany extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'name',
        'domain',
        'phone',
        'external_id',
        'tags',
        'first_seen_at',
        'last_activity_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'first_seen_at' => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }

    /**
     * Normalised on the way in — matching depends on it, and so does the unique index.
     *
     * A bare host, never a URL: somebody pasting `https://acme.com/` into the panel and the
     * ingest deriving `acme.com` from an address must land on the same row, or the index simply
     * records both spellings as two companies.
     */
    public function setDomainAttribute(?string $value): void
    {
        $this->attributes['domain'] = self::normaliseDomain($value);
    }

    public static function normaliseDomain(?string $value): ?string
    {
        $value = mb_strtolower(trim((string) $value));

        if ($value === '') {
            return null;
        }

        /*
         * Strip a scheme, any path, and a leading `www.` — three spellings of one company.
         *
         * `~` delimiters, NOT `#`: the second pattern's character class contains a literal `#`
         * (a URL fragment), which closes a `#`-delimited pattern mid-class and leaves PHP
         * reading `].*$` as modifiers. It failed silently, returned null, and every domain
         * normalised to an empty string — so `acme.com` never matched and every ticket created
         * a new company.
         */
        $value = (string) preg_replace('~^[a-z][a-z0-9+.-]*://~', '', $value);
        $value = (string) preg_replace('~[/?#].*$~', '', $value);
        $value = ltrim($value, '@');
        $value = (string) preg_replace('~^www\.~', '', $value);

        return $value === '' ? null : $value;
    }

    /** The domain part of an email address, or null if there is not one. */
    public static function domainOfEmail(?string $email): ?string
    {
        $email = mb_strtolower(trim((string) $email));
        $at = mb_strrpos($email, '@');

        if ($at === false) {
            return null;
        }

        return self::normaliseDomain(mb_substr($email, $at + 1));
    }

    public function customers(): HasMany
    {
        return $this->hasMany(HelpCenterCustomer::class, 'help_center_company_id');
    }

    public function requests(): HasMany
    {
        return $this->hasMany(HelpCenterRequest::class, 'help_center_company_id');
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->where('name', 'like', '%'.$term.'%')
            ->orWhere('domain', 'like', '%'.$term.'%')
            ->orWhere('external_id', 'like', '%'.$term.'%'));
    }

    public function displayName(): string
    {
        return trim((string) $this->name) ?: (string) ($this->domain ?? 'Unnamed company');
    }

    /** @return array<int, string> */
    public function tagList(): array
    {
        return array_values(array_map('strval', (array) $this->tags));
    }

    /**
     * The Company block on a ticket panel and the row in the Companies list (§10, §11).
     *
     * The counts are computed here rather than denormalised: unlike a Customer's Last Ticket,
     * which the grid needs for every row, these are read one company at a time on a profile.
     * The LIST passes them in from an aggregate query instead — see CompanyDirectory.
     *
     * @return array<string, mixed>
     */
    public function toPanel(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->displayName(),
            'domain' => $this->domain,
            'phone' => $this->phone,
            'external_id' => $this->external_id,
            'tags' => $this->tagList(),
            'initial' => mb_strtoupper(mb_substr($this->displayName(), 0, 1)) ?: '?',
            'first_seen' => $this->first_seen_at?->format('M j, Y'),
            'last_activity' => $this->last_activity_at?->format('M j, Y'),
            'customers' => $this->customers()->count(),
            'total_tickets' => $this->requests()->count(),
            'open_tickets' => $this->requests()->whereNull('closed_at')->where('is_spam', false)->count(),
        ];
    }
}
