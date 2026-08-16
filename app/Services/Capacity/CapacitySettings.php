<?php

namespace App\Services\Capacity;

use App\Models\MemberCapacity;
use App\Models\WorkspaceSettings;

/**
 * The workspace's working schedule and thresholds (§12–§15, §29).
 *
 * Resolved once and passed down, rather than each calculator reaching for the settings row on
 * its own: the report, the cycle planner and the assignment warning have to be measuring
 * against the same working week, and a shared object is what makes that structural.
 *
 * Memoized per instance. This is asked once per member per report — dozens of times on one
 * screen — and the answer cannot change mid-request.
 */
class CapacitySettings
{
    private ?WorkspaceSettings $settings = null;

    /** @var array<int, array{hours: float, days: array<int, int>}>|null */
    private ?array $overrides = null;

    public function enabled(): bool
    {
        return (bool) $this->settings()?->capacity_enabled;
    }

    public function hoursPerDay(): float
    {
        return (float) ($this->settings()?->capacity_hours_per_day ?: config('settings.capacity.hours_per_day'));
    }

    /**
     * The workspace's working weekdays, ISO-8601 (1 = Monday).
     *
     * @return array<int, int>
     */
    public function workingDays(): array
    {
        $days = $this->settings()?->capacity_working_days;

        // An empty list is not "works no days" — it is a workspace that has not chosen yet.
        // Honouring it literally would report every member as having zero capacity and
        // therefore infinite utilization, which reads as a system fault rather than a setting.
        return $days ? array_map('intval', $days) : array_map('intval', config('settings.capacity.working_days'));
    }

    /** `hours × days` (§15). Derived, never stored — see CAP-D7. */
    public function weeklyHours(): float
    {
        return round($this->hoursPerDay() * count($this->workingDays()), 2);
    }

    public function nearThreshold(): int
    {
        return (int) ($this->settings()?->capacity_near_threshold ?: config('settings.capacity.thresholds.near'));
    }

    public function overThreshold(): int
    {
        return (int) ($this->settings()?->capacity_over_threshold ?: config('settings.capacity.thresholds.over'));
    }

    public function highThreshold(): int
    {
        return (int) ($this->settings()?->capacity_high_threshold ?: config('settings.capacity.thresholds.high'));
    }

    /**
     * This member's schedule — their override, or the workspace default (§16).
     *
     * @return array{hours: float, days: array<int, int>}
     */
    public function scheduleFor(int $userId): array
    {
        return $this->overrides()[$userId] ?? ['hours' => $this->hoursPerDay(), 'days' => $this->workingDays()];
    }

    public function hasOverride(int $userId): bool
    {
        return isset($this->overrides()[$userId]);
    }

    /** @return array<int, array{hours: float, days: array<int, int>}> */
    private function overrides(): array
    {
        if ($this->overrides !== null) {
            return $this->overrides;
        }

        return $this->overrides = MemberCapacity::query()
            ->get()
            ->mapWithKeys(fn (MemberCapacity $m) => [(int) $m->user_id => [
                'hours' => (float) $m->hours_per_day,
                // An override may set different hours while keeping the workspace's days.
                'days' => $m->working_days ? array_map('intval', $m->working_days) : $this->workingDays(),
            ]])
            ->all();
    }

    private function settings(): ?WorkspaceSettings
    {
        return $this->settings ??= WorkspaceSettings::query()->first();
    }
}
