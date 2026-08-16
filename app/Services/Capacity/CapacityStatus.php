<?php

namespace App\Services\Capacity;

/**
 * Utilization → a word (§28).
 *
 * Applied independently to planned and actual, because they answer different questions and a
 * member can easily be Normal on one and High Workload on the other (§28's own example).
 *
 * Workload language only. This says how many hours are booked against how many are available;
 * it does not diagnose anybody.
 */
class CapacityStatus
{
    public const NORMAL = 'normal';

    public const NEAR = 'near';

    public const OVER = 'over';

    public const HIGH = 'high';

    public function __construct(private readonly CapacitySettings $settings) {}

    /**
     * Utilization as a percentage, or null when there is no capacity to measure against.
     *
     * Null rather than 0 or 100 when available hours are zero: somebody with no working days
     * in the window is not "fully utilized" and not "idle", they are not scheduled — and
     * dividing by zero to reach either answer would put a fabricated number on a screen
     * managers make staffing decisions from.
     */
    public function utilization(float $hours, float $available): ?float
    {
        return $available <= 0 ? null : round($hours / $available * 100, 1);
    }

    /** @return self::NORMAL|self::NEAR|self::OVER|self::HIGH|null */
    public function classify(?float $utilization): ?string
    {
        if ($utilization === null) {
            return null;
        }

        return match (true) {
            $utilization >= $this->settings->highThreshold() => self::HIGH,
            $utilization >= $this->settings->overThreshold() => self::OVER,
            $utilization >= $this->settings->nearThreshold() => self::NEAR,
            default => self::NORMAL,
        };
    }

    public function label(?string $status): string
    {
        return match ($status) {
            self::HIGH => 'High Workload',
            self::OVER => 'Over Capacity',
            self::NEAR => 'Near Capacity',
            self::NORMAL => 'Normal',
            default => 'Not scheduled',
        };
    }

    /** The colour each status carries wherever it is shown, so the report and a chip agree. */
    public function color(?string $status): string
    {
        return match ($status) {
            self::HIGH => '#DC2626',
            self::OVER => '#F97316',
            self::NEAR => '#EAB308',
            self::NORMAL => '#22C55E',
            default => '#9CA3AF',
        };
    }

    /** @return array{key: ?string, label: string, color: string, value: ?float} */
    public function describe(float $hours, float $available): array
    {
        $utilization = $this->utilization($hours, $available);
        $status = $this->classify($utilization);

        return [
            'key' => $status,
            'label' => $this->label($status),
            'color' => $this->color($status),
            'value' => $utilization,
        ];
    }
}
