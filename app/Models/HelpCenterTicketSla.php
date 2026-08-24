<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * A ticket's SLA instance (docs/features/helpdesk-sla.md, §37). TENANT-SCOPED.
 *
 * One per Request. It records WHICH policy applies and under what settings; the clocks are
 * `timers`, because Next Response repeats and repeated things are rows.
 */
class HelpCenterTicketSla extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'help_center_request_id',
        'help_center_sla_policy_id',
        'policy_name',
        'applied_at',
        'applied_by',
        'timezone',
        'warning_percent',
    ];

    protected function casts(): array
    {
        return [
            'applied_at' => 'datetime',
            'warning_percent' => 'integer',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(HelpCenterRequest::class, 'help_center_request_id');
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(HelpCenterSlaPolicy::class, 'help_center_sla_policy_id');
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    public function timers(): HasMany
    {
        return $this->hasMany(HelpCenterSlaTimer::class, 'help_center_ticket_sla_id');
    }

    /**
     * What to call the policy on screen.
     *
     * The snapshot first, the live row second. A deleted policy leaves the FK null and the name
     * behind, and a ticket's history that suddenly reads "no SLA" because somebody tidied up the
     * settings page is a history that has lost the only fact it was keeping.
     */
    public function displayName(): string
    {
        return $this->policy_name ?? $this->policy?->name ?? 'No SLA';
    }

    /** Was this SLA put here by a person (§31) rather than by evaluation (§13)? */
    public function wasManual(): bool
    {
        return $this->applied_by !== null;
    }

    /**
     * The timer the ticket is judged by right now (§27).
     *
     * "Most urgent" is: a breach beats everything, then the soonest deadline among the clocks
     * that are still moving. A paused or completed clock is not urgent — nobody owes anything on
     * it — so neither can win, which is what stops a resolved ticket showing a red chip forever.
     */
    public function mostUrgentTimer(): ?HelpCenterSlaTimer
    {
        $timers = $this->relationLoaded('timers') ? $this->timers : $this->timers()->get();

        $breached = $timers->where('status', HelpCenterSlaTimer::STATUS_BREACHED)
            ->sortBy('due_at')->first();

        if ($breached) {
            return $breached;
        }

        return $timers->filter(fn (HelpCenterSlaTimer $t) => $t->isRunning() && $t->due_at !== null)
            ->sortBy('due_at')
            ->first();
    }
}
