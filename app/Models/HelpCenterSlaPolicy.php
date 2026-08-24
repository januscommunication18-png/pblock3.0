<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * A Business SLA policy (docs/features/helpdesk-sla.md, §3–§4, §12–§14). TENANT-SCOPED.
 *
 * The policy IS the assignment rule (SLA-D5): what it promises (`targets`) and when it applies
 * (`conditions`) are one object, because §14 orders policies and applies the first that matches.
 */
class HelpCenterSlaPolicy extends Model
{
    use BelongsToTenant;

    public const REOPEN_RESUME = 'resume';

    public const REOPEN_RESTART = 'restart';

    public const REOPEN_NONE = 'none';

    public const MATCH_ALL = 'all';

    public const MATCH_ANY = 'any';

    protected $fillable = [
        'tenant_id',
        'help_center_space_id',
        'name',
        'description',
        'is_active',
        'is_default',
        'help_center_business_hours_id',
        'warning_percent',
        'match_type',
        'conditions',
        'reopen_behavior',
        'position',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'conditions' => 'array',
            'warning_percent' => 'integer',
            'position' => 'integer',
        ];
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(HelpCenterSpace::class, 'help_center_space_id');
    }

    public function businessHours(): BelongsTo
    {
        return $this->belongsTo(HelpCenterBusinessHours::class, 'help_center_business_hours_id');
    }

    public function targets(): HasMany
    {
        return $this->hasMany(HelpCenterSlaTarget::class, 'help_center_sla_policy_id');
    }

    public function escalations(): HasMany
    {
        return $this->hasMany(HelpCenterSlaEscalation::class, 'help_center_sla_policy_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Evaluation order (§14): first match wins, so this ordering IS the rule. */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * The target for a priority, falling back to the policy's Medium row.
     *
     * A policy that has not been given a row for every priority is the normal case while it is
     * being authored, and a ticket that arrives in the gap needs an answer. `normal` is that
     * answer because it is the product's own default priority — the one a Request gets when
     * nobody said otherwise.
     */
    public function targetFor(string $priority): ?HelpCenterSlaTarget
    {
        $targets = $this->relationLoaded('targets') ? $this->targets : $this->targets()->get();

        return $targets->firstWhere('priority', $priority)
            ?? $targets->firstWhere('priority', 'normal');
    }

    /** The timezone this policy's clocks run in — the calendar's, or UTC when it is 24/7. */
    public function timezone(): string
    {
        return $this->businessHours?->timezone ?? 'UTC';
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'is_active' => (bool) $this->is_active,
            'is_default' => (bool) $this->is_default,
            'business_hours_id' => $this->help_center_business_hours_id,
            'business_hours_name' => $this->businessHours?->name,
            'warning_percent' => (int) $this->warning_percent,
            'match_type' => $this->match_type,
            'conditions' => (array) ($this->conditions ?? []),
            'reopen_behavior' => $this->reopen_behavior,
            'position' => (int) $this->position,
            'targets' => $this->targets->map->toPayload()->values()->all(),
        ];
    }
}
