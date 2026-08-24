<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * An escalation rule (docs/features/helpdesk-sla.md, §24–§25). TENANT-SCOPED.
 *
 * Space-level, because that is where §24 puts the screen. A rule may narrow itself to one policy
 * and to one kind of clock; both null is "any SLA, any clock in this Space", which is the rule
 * most teams actually want ("tell somebody when anything breaches").
 */
class HelpCenterSlaEscalation extends Model
{
    use BelongsToTenant;

    public const TRIGGER_DUE_SOON = 'due_soon';

    public const TRIGGER_BREACHED = 'breached';

    protected $fillable = [
        'tenant_id',
        'help_center_space_id',
        'help_center_sla_policy_id',
        'name',
        'trigger',
        'kind',
        'actions',
        'is_active',
        'position',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'actions' => 'array',
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(HelpCenterSpace::class, 'help_center_space_id');
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(HelpCenterSlaPolicy::class, 'help_center_sla_policy_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(HelpCenterSlaEscalationRun::class, 'help_center_sla_escalation_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * The percentage this rule watches for, or null when it fires on a state instead.
     *
     * `due_soon` and `breached` have no percentage of their own on purpose: Due Soon is the
     * POLICY's threshold (§17), which a rule must not be able to contradict, and a breach is
     * 100% by definition.
     */
    public function percent(): ?int
    {
        $percent = config('help-center.sla_escalation_triggers.'.$this->trigger.'.percent');

        return $percent === null ? null : (int) $percent;
    }

    /** Does this rule watch the given timer? */
    public function watches(HelpCenterSlaTimer $timer): bool
    {
        if ($this->kind !== null && $this->kind !== $timer->kind) {
            return false;
        }

        return $this->help_center_sla_policy_id === null
            || $this->help_center_sla_policy_id === $timer->ticketSla?->help_center_sla_policy_id;
    }

    public function triggerLabel(): string
    {
        $label = (string) config('help-center.sla_escalation_triggers.'.$this->trigger.'.label', $this->trigger);

        // "SLA Breached" reads as any clock; naming the stage is the only thing that turns it
        // into §24's "First Response SLA Breached", which is a rule somebody wrote on purpose.
        if ($this->kind !== null) {
            $kind = (string) config('help-center.sla_timer_kinds.'.$this->kind.'.label', $this->kind);

            return str_replace('SLA', $kind.' SLA', $label);
        }

        return $label;
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'trigger' => $this->trigger,
            'trigger_label' => $this->triggerLabel(),
            'kind' => $this->kind,
            'policy_id' => $this->help_center_sla_policy_id,
            'actions' => (array) ($this->actions ?? []),
            'is_active' => (bool) $this->is_active,
            'position' => (int) $this->position,
        ];
    }
}
