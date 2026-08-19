<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * Where incoming customer conversations are delivered (docs/features/help-center.md §5).
 * TENANT-SCOPED.
 *
 * Every Inbox owns exactly one generated inbound identifier (§20 rules 4 and 5) and may carry
 * several customer-facing addresses that all route into it (§20 rule 3).
 */
class HelpCenterInbox extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'help_center_space_id',
        'name',
        'inbound_id',
        'setup_completed_at',
        'created_by',
        'position',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'setup_completed_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(HelpCenterSpace::class, 'help_center_space_id');
    }

    public function emailAddresses(): HasMany
    {
        return $this->hasMany(HelpCenterEmailAddress::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /**
     * The address customers' mail is forwarded to (§8).
     *
     * Composed here rather than stored (HC-D5), so every screen that shows it shows the same
     * thing and a domain change is an env change.
     */
    public function inboundAddress(): string
    {
        return config('help-center.inbound_prefix', 'inbox').'-'.$this->inbound_id
            .'@'.config('help-center.inbound_domain');
    }

    /** Has the setup wizard been finished for this Inbox? (HC-D3) */
    public function isSetUp(): bool
    {
        return $this->setup_completed_at !== null;
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'space_id' => $this->help_center_space_id,
            'name' => $this->name,
            'inbound_address' => $this->inboundAddress(),
            'set_up' => $this->isSetUp(),
            'addresses' => $this->relationLoaded('emailAddresses')
                ? $this->emailAddresses->map->toPayload()->all()
                : [],
        ];
    }
}
