<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * One SLA clock (docs/features/helpdesk-sla.md, §9–§11, §16, §18). TENANT-SCOPED.
 *
 * This model holds STATE and the vocabulary for reading it. Advancing a clock — starting,
 * pausing, resuming, completing, breaching — belongs to the engine (S2), not here: the
 * arithmetic needs the policy's calendar and the Space's holidays, and a model that reaches for
 * both is a model that cannot be reasoned about from its own row.
 */
class HelpCenterSlaTimer extends Model
{
    use BelongsToTenant;

    public const STATUS_NOT_STARTED = 'not_started';

    public const STATUS_RUNNING = 'running';

    public const STATUS_DUE_SOON = 'due_soon';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_BREACHED = 'breached';

    protected $fillable = [
        'tenant_id',
        'help_center_ticket_sla_id',
        'help_center_request_id',
        'kind',
        'cycle',
        'status',
        'target_minutes',
        'target_value',
        'target_unit',
        'started_at',
        'due_at',
        'paused_at',
        'paused_minutes',
        'completed_at',
        'breached_at',
        'over_minutes',
        'elapsed_minutes',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'due_at' => 'datetime',
            'paused_at' => 'datetime',
            'completed_at' => 'datetime',
            'breached_at' => 'datetime',
            'cycle' => 'integer',
            'target_minutes' => 'integer',
            'paused_minutes' => 'integer',
        ];
    }

    public function ticketSla(): BelongsTo
    {
        return $this->belongsTo(HelpCenterTicketSla::class, 'help_center_ticket_sla_id');
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(HelpCenterRequest::class, 'help_center_request_id');
    }

    public function escalationRuns(): HasMany
    {
        return $this->hasMany(HelpCenterSlaEscalationRun::class, 'help_center_sla_timer_id');
    }

    /**
     * Is this clock still moving?
     *
     * Running and Due Soon are the same fact about the clock and differ only in how loudly the
     * screen says it (§16, §17). Every caller that asks "is time passing?" must treat them
     * alike, so they ask this rather than comparing to a string.
     */
    public function isRunning(): bool
    {
        return in_array($this->status, [self::STATUS_RUNNING, self::STATUS_DUE_SOON], true);
    }

    /** Has this clock reached a state it will not leave on its own? */
    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_BREACHED], true);
    }

    public function kindLabel(): string
    {
        return (string) config('help-center.sla_timer_kinds.'.$this->kind.'.label', $this->kind);
    }

    public function statusLabel(): string
    {
        return (string) config('help-center.sla_timer_statuses.'.$this->status.'.label', $this->status);
    }

    public function statusColor(): ?string
    {
        return config('help-center.sla_timer_statuses.'.$this->status.'.color');
    }

    /** Only the clocks the sweep (SLA-D10) has to look at. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_RUNNING, self::STATUS_DUE_SOON]);
    }

    /**
     * How much of the budget has been spent, 0–100 — the number escalation triggers fire on.
     *
     * Measured against `due_at` rather than against elapsed business minutes, because `due_at`
     * already has every pause and every closed hour baked into it (SLA-D9). Two ways of
     * computing the same percentage is two answers to "has this hit 90%?".
     */
    public function percentConsumed(?\DateTimeInterface $now = null): ?int
    {
        if ($this->started_at === null || $this->due_at === null || ! $this->target_minutes) {
            return null;
        }

        $now = $now ? $this->asDateTime($now) : now();
        $total = $this->started_at->diffInSeconds($this->due_at, absolute: true);

        if ($total <= 0) {
            return 100;
        }

        $spent = $this->started_at->diffInSeconds($now, absolute: false);

        return (int) max(0, min(100, round($spent / $total * 100)));
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'kind_label' => $this->kindLabel(),
            'cycle' => (int) $this->cycle,
            'status' => $this->status,
            'status_label' => $this->statusLabel(),
            'status_color' => $this->statusColor(),
            'target_minutes' => $this->target_minutes,
            'due_at' => $this->due_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'breached_at' => $this->breached_at?->toIso8601String(),
            'over_minutes' => $this->over_minutes,
            'elapsed_minutes' => $this->elapsed_minutes,
        ];
    }
}
