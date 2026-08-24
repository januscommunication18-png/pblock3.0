<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * One escalation that has already fired (docs/features/helpdesk-sla.md, SLA-D11). TENANT-SCOPED.
 *
 * The row IS the guard: the unique index on (escalation, timer) is what makes "Notify Team Lead"
 * happen once rather than once per sweep. Nothing reads this to decide what to do — only whether
 * it has been done.
 */
class HelpCenterSlaEscalationRun extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'help_center_sla_escalation_id',
        'help_center_sla_timer_id',
        'fired_at',
        'result',
    ];

    protected function casts(): array
    {
        return [
            'fired_at' => 'datetime',
            'result' => 'array',
        ];
    }

    public function escalation(): BelongsTo
    {
        return $this->belongsTo(HelpCenterSlaEscalation::class, 'help_center_sla_escalation_id');
    }

    public function timer(): BelongsTo
    {
        return $this->belongsTo(HelpCenterSlaTimer::class, 'help_center_sla_timer_id');
    }
}
