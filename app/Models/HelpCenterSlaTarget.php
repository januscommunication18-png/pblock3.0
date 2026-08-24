<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * What a policy promises at one priority (docs/features/helpdesk-sla.md, §8). TENANT-SCOPED.
 *
 * Three stages, each a VALUE and a UNIT (SLA-D7). Nothing here converts to minutes: "2 Business
 * Days" only has a length once a calendar is in hand, and the calendar can change under a
 * policy that has already been written.
 */
class HelpCenterSlaTarget extends Model
{
    use BelongsToTenant;

    public const KIND_FIRST_RESPONSE = 'first_response';

    public const KIND_NEXT_RESPONSE = 'next_response';

    public const KIND_RESOLUTION = 'resolution';

    public const UNIT_MINUTES = 'minutes';

    public const UNIT_HOURS = 'hours';

    public const UNIT_DAYS = 'days';

    protected $fillable = [
        'tenant_id',
        'help_center_sla_policy_id',
        'priority',
        'first_response_value',
        'first_response_unit',
        'next_response_value',
        'next_response_unit',
        'resolution_value',
        'resolution_unit',
    ];

    public function policy(): BelongsTo
    {
        return $this->belongsTo(HelpCenterSlaPolicy::class, 'help_center_sla_policy_id');
    }

    /** The three stages, in the order §8's table draws them. */
    public static function kinds(): array
    {
        return array_keys((array) config('help-center.sla_timer_kinds'));
    }

    /**
     * The authored duration for one stage, or null when the policy promises nothing there.
     *
     * Null and not zero: "no target" and "due immediately" are different commitments, and a
     * missing row read as zero would breach every ticket the moment it arrived.
     *
     * @return array{value: int, unit: string}|null
     */
    public function duration(string $kind): ?array
    {
        $value = $this->{$kind.'_value'};
        $unit = $this->{$kind.'_unit'};

        if ($value === null || $unit === null || (int) $value <= 0) {
            return null;
        }

        return ['value' => (int) $value, 'unit' => (string) $unit];
    }

    /** "2 Business Days", "15 Minutes" — the phrase §8 and §15 both show. */
    public function label(string $kind): string
    {
        $duration = $this->duration($kind);

        if ($duration === null) {
            return '—';
        }

        $unit = (string) config('help-center.sla_units.'.$duration['unit'].'.label', $duration['unit']);

        // "1 Hours" is the tell that nobody read the string back. Singular is one `rtrim`.
        return $duration['value'].' '.($duration['value'] === 1 ? rtrim($unit, 's') : $unit);
    }

    public function priorityLabel(): string
    {
        return (string) config('help-center.priorities.'.$this->priority.'.label', $this->priority);
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        $payload = [
            'priority' => $this->priority,
            'priority_label' => $this->priorityLabel(),
        ];

        foreach (self::kinds() as $kind) {
            $payload[$kind] = $this->duration($kind);
            $payload[$kind.'_label'] = $this->label($kind);
        }

        return $payload;
    }
}
