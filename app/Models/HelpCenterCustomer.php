<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * The person who writes in (docs/features/help-center.md, P33). TENANT-SCOPED.
 *
 * One record per email address per workspace. The Request keeps its own `customer_email` and
 * `customer_name` — those are what a particular message said — while this is what the workspace
 * knows about the person across all of them.
 */
class HelpCenterCustomer extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'email',
        'name',
        // The free-text company, kept alongside the Company ROW below (P75).
        //
        // Not replaced by it: this holds what somebody typed into the panel before Companies
        // were rows, and the mapping table still offers it as "Customer → Company (text)".
        'company',
        'help_center_company_id',
        'phone',
        'external_id',
        'tags',
        'first_contact_at',
        'last_activity_at',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'first_contact_at' => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }

    /** Normalised on the way in, like every other address in this module — matching depends on it. */
    public function setEmailAttribute(?string $value): void
    {
        $this->attributes['email'] = $value === null ? null : mb_strtolower(trim($value));
    }

    public function requests(): HasMany
    {
        return $this->hasMany(HelpCenterRequest::class, 'help_center_customer_id');
    }

    /**
     * The organisation they belong to (P75 §7), once one has been matched or created.
     *
     * `companyRecord`, NOT `company`: this table already has a free-text `company` COLUMN, and
     * Eloquent resolves an attribute before a relation of the same name — `$customer->company`
     * would hand back the typed string while `with('company')` eager-loaded a model nobody could
     * then read. Two different things named the same thing is the bug; the longer name is the
     * fix.
     */
    public function companyRecord(): BelongsTo
    {
        return $this->belongsTo(HelpCenterCompany::class, 'help_center_company_id');
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->where('name', 'like', '%'.$term.'%')
            ->orWhere('email', 'like', '%'.$term.'%')
            ->orWhere('external_id', 'like', '%'.$term.'%'));
    }

    /** @return array<int, string> */
    public function tagList(): array
    {
        return array_values(array_map('strval', (array) $this->tags));
    }

    /** The domain of their address — what Company matching falls back to (P75 §7). */
    public function emailDomain(): ?string
    {
        return HelpCenterCompany::domainOfEmail($this->email);
    }

    /** What to call them when nobody filled in a name — never a blank space on a panel. */
    public function displayName(): string
    {
        return trim((string) $this->name) ?: $this->email;
    }

    /**
     * The panel's block for this person, counts included.
     *
     * `total_tickets` counts every Request from them in the workspace, across Spaces — see the
     * migration's note on why the record is workspace-scoped. `last_contact_at` is the most
     * recent message rather than the most recent ticket: a thread that has run for a week is
     * more recent contact than a ticket opened yesterday and never answered.
     *
     * @return array<string, mixed>
     */
    public function toPanel(): array
    {
        $requests = $this->requests();

        return [
            'id' => $this->id,
            'name' => $this->displayName(),
            'email' => $this->email,
            'company' => $this->company,
            'phone' => $this->phone,
            'tags' => $this->tagList(),
            /*
             * The Company ROW, flattened rather than nested (P75 §10).
             *
             * The panel draws a name and a link, and a nested object would mean every reader
             * checking for null twice — once for the relation and once for the field on it.
             */
            'company_id' => $this->help_center_company_id,
            'company_name' => $this->companyRecord?->displayName(),
            'company_domain' => $this->companyRecord?->domain,
            'external_id' => $this->external_id,
            'initial' => mb_strtoupper(mb_substr($this->displayName(), 0, 1)) ?: '?',
            'first_contact' => $this->first_contact_at?->format('M j, Y'),
            'total_tickets' => (clone $requests)->count(),
            'open_tickets' => (clone $requests)->whereNull('closed_at')->where('is_spam', false)->count(),
            'last_contact' => (clone $requests)->max('last_message_at'),
        ];
    }
}
