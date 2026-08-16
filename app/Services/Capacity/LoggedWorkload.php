<?php

namespace App\Services\Capacity;

use App\Models\WorkItemWorklog;

/**
 * Hours actually logged in a window (§25, CAP-015).
 *
 * The simple half of the feature, and the trustworthy one: worklogs already carry a person, a
 * date and a duration, so there is nothing to convert, spread or apportion. Where planned
 * capacity is a forecast built from several rules, this is a record.
 *
 * Grouped in SQL rather than loaded and summed in PHP — a busy workspace-quarter is tens of
 * thousands of rows, and none of their detail is wanted here.
 */
class LoggedWorkload
{
    /**
     * @param  array<int, int>|null  $projectIds
     * @return array<int, float> user id => logged hours
     */
    public function forWindow(CapacityWindow $window, ?array $projectIds = null): array
    {
        return WorkItemWorklog::query()
            ->whereBetween('work_date', [$window->start->toDateString(), $window->end->toDateString()])
            ->when($projectIds !== null, fn ($q) => $q->whereIn('project_id', $projectIds))
            ->groupBy('user_id')
            ->selectRaw('user_id, SUM(minutes_logged) as total_minutes')
            ->pluck('total_minutes', 'user_id')
            ->map(fn ($minutes) => round(((int) $minutes) / 60, 2))
            ->all();
    }
}
