<?php

namespace App\Services\Capacity;

use Carbon\CarbonImmutable;
use DatePeriod;

/**
 * The stretch of calendar a capacity figure is about (docs/features/work-capacity.md).
 *
 * Capacity means nothing without a window — "Sarah is at 90%" is only true of some period —
 * so every calculation here takes one rather than assuming "this week" and then disagreeing
 * with the screen that assumed "this cycle".
 *
 * Inclusive of both ends: a one-day window is a real thing to ask for, and a half-open range
 * makes "Monday to Friday" read as four days to everyone who has to maintain it.
 */
class CapacityWindow
{
    public readonly CarbonImmutable $start;

    public readonly CarbonImmutable $end;

    public function __construct(CarbonImmutable|string $start, CarbonImmutable|string $end)
    {
        $start = CarbonImmutable::parse($start)->startOfDay();
        $end = CarbonImmutable::parse($end)->startOfDay();

        // Tolerate a reversed pair rather than producing a window with negative days, which
        // silently reports everybody at 0% instead of failing where the mistake was made.
        $this->start = $start->min($end);
        $this->end = $start->max($end);
    }

    /** The ISO week (Monday–Sunday) containing a date. The default reporting period (§15). */
    public static function week(CarbonImmutable|string|null $anchor = null): self
    {
        $anchor = CarbonImmutable::parse($anchor ?? now())->startOfDay();

        return new self($anchor->startOfWeek(CarbonImmutable::MONDAY), $anchor->endOfWeek(CarbonImmutable::SUNDAY));
    }

    /** @return array<int, CarbonImmutable> every date in the window, inclusive */
    public function days(): array
    {
        // DatePeriod yields plain DateTimeImmutable even when seeded with Carbon, so the
        // mapping is not decoration — without it callers get objects with no dayOfWeekIso.
        return array_map(
            fn (\DateTimeInterface $d) => CarbonImmutable::instance($d),
            iterator_to_array(new DatePeriod($this->start, new \DateInterval('P1D'), $this->end->addDay())),
        );
    }

    /**
     * The days in this window that fall on one of the given ISO weekdays.
     *
     * @param  array<int, int>  $weekdays  ISO-8601: 1 = Monday … 7 = Sunday
     * @return array<int, CarbonImmutable>
     */
    public function workingDays(array $weekdays): array
    {
        return array_values(array_filter(
            $this->days(),
            fn (CarbonImmutable $d) => in_array((int) $d->dayOfWeekIso, $weekdays, true),
        ));
    }

    public function overlaps(self $other): bool
    {
        return $this->start <= $other->end && $this->end >= $other->start;
    }

    /** The part of this window that also falls inside another, or null when they miss. */
    public function intersect(self $other): ?self
    {
        if (! $this->overlaps($other)) {
            return null;
        }

        return new self($this->start->max($other->start), $this->end->min($other->end));
    }

    public function label(): string
    {
        return $this->start->format('M j').' – '.$this->end->format('M j, Y');
    }

    /** @return array{start: string, end: string, label: string} */
    public function toArray(): array
    {
        return [
            'start' => $this->start->toDateString(),
            'end' => $this->end->toDateString(),
            'label' => $this->label(),
        ];
    }
}
