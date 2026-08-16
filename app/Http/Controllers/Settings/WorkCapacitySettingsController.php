<?php

namespace App\Http\Controllers\Settings;

use App\Http\Requests\Settings\UpdateWorkCapacityRequest;
use App\Services\Capacity\CapacitySettings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;

/**
 * Administration > Work Capacity (§12–§15, §29).
 *
 * Owner/admin only, like every settings section (CAP-020): the working week and the thresholds
 * decide what "overloaded" means for everybody in the workspace.
 */
class WorkCapacitySettingsController extends SettingsController
{
    /** GET /settings/work-capacity */
    public function show(CapacitySettings $capacity): View
    {
        $this->guardManage();

        return $this->page('work-capacity', [
            'enabled' => $capacity->enabled(),
            'hoursPerDay' => $capacity->hoursPerDay(),
            'workingDays' => $capacity->workingDays(),
            // Sent, not computed in the browser, so the screen and the report agree on §15
            // even before anything is saved.
            'weeklyHours' => $capacity->weeklyHours(),
            'thresholds' => [
                'near' => $capacity->nearThreshold(),
                'over' => $capacity->overThreshold(),
                'high' => $capacity->highThreshold(),
            ],
            'limits' => [
                'min' => (float) config('settings.capacity.min_hours_per_day'),
                'max' => (float) config('settings.capacity.max_hours_per_day'),
            ],
            'weekdays' => [
                ['value' => 1, 'label' => 'Monday', 'short' => 'Mon'],
                ['value' => 2, 'label' => 'Tuesday', 'short' => 'Tue'],
                ['value' => 3, 'label' => 'Wednesday', 'short' => 'Wed'],
                ['value' => 4, 'label' => 'Thursday', 'short' => 'Thu'],
                ['value' => 5, 'label' => 'Friday', 'short' => 'Fri'],
                ['value' => 6, 'label' => 'Saturday', 'short' => 'Sat'],
                ['value' => 7, 'label' => 'Sunday', 'short' => 'Sun'],
            ],
            'endpoints' => [
                'update' => route('settings.work-capacity.update'),
                'toggle' => route('settings.work-capacity.toggle'),
            ],
        ]);
    }

    /** POST /settings/work-capacity/toggle (CAP-001) */
    public function toggle(): JsonResponse
    {
        $this->guardManage();

        $settings = $this->setFeature('capacity_enabled', request()->boolean('enabled'));

        return response()->json(['ok' => true, 'enabled' => (bool) $settings->capacity_enabled]);
    }

    /** PATCH /settings/work-capacity (CAP-002, CAP-018) */
    public function update(UpdateWorkCapacityRequest $request, CapacitySettings $capacity): JsonResponse
    {
        $this->guardManage();

        $days = array_values(array_unique(array_map('intval', $request->validated('working_days'))));
        sort($days);

        $this->settings()->forceFill([
            'capacity_hours_per_day' => $request->validated('hours_per_day'),
            'capacity_working_days' => $days,
            'capacity_near_threshold' => $request->validated('near_threshold'),
            'capacity_over_threshold' => $request->validated('over_threshold'),
            'capacity_high_threshold' => $request->validated('high_threshold'),
        ])->save();

        // Re-resolved rather than recomputed here: the weekly figure the screen shows next has
        // to come from the same place the report reads it (CAP-D7).
        $fresh = app(CapacitySettings::class);

        return response()->json([
            'ok' => true,
            'hoursPerDay' => $fresh->hoursPerDay(),
            'workingDays' => $fresh->workingDays(),
            'weeklyHours' => $fresh->weeklyHours(),
        ]);
    }
}
