<?php

namespace App\Services\Capacity;

/**
 * How many hours a member actually has in a window (§15, §16).
 *
 * Counts the member's own working days inside the window and multiplies by their own daily
 * hours, rather than scaling a weekly figure by the number of weeks. A three-day window, a
 * cycle that starts on a Wednesday, or a fortnight containing a Monday holiday all give the
 * wrong answer under proportional scaling and the right one by counting days.
 *
 * Phase 4 subtracts absences here; nothing else will need to change, because every screen
 * already asks this one question.
 */
class AvailableCapacity
{
    public function __construct(private readonly CapacitySettings $settings) {}

    public function forMember(int $userId, CapacityWindow $window): float
    {
        $schedule = $this->settings->scheduleFor($userId);

        return round(count($window->workingDays($schedule['days'])) * $schedule['hours'], 2);
    }

    /**
     * @param  array<int, int>  $userIds
     * @return array<int, float> user id => available hours
     */
    public function forMembers(array $userIds, CapacityWindow $window): array
    {
        $out = [];

        foreach ($userIds as $id) {
            $out[(int) $id] = $this->forMember((int) $id, $window);
        }

        return $out;
    }
}
