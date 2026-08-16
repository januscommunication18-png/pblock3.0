<?php

namespace App\Services\Capacity;

use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Collection;

/**
 * The Team Capacity screen's numbers (§31, §32).
 *
 * The one place the row shape is decided. The workspace dashboard, the project report and the
 * cycle planner all read it, so they cannot drift into disagreeing about what "planned" means
 * — which is exactly how two screens end up showing one person two utilizations.
 */
class TeamCapacityReport
{
    public function __construct(
        private readonly CapacitySettings $settings,
        private readonly AvailableCapacity $available,
        private readonly PlannedWorkload $planned,
        private readonly LoggedWorkload $logged,
        private readonly CapacityStatus $status,
    ) {}

    /**
     * @param  array<int, int>|null  $projectIds  restrict to these projects, or null for the workspace
     * @param  array<int, int>|null  $visibleUserIds  restrict to these members (§46: a contributor
     *                                                sees only themselves)
     * @return array<string, mixed>
     */
    public function build(CapacityWindow $window, ?array $projectIds = null, ?array $visibleUserIds = null): array
    {
        $members = $this->members($visibleUserIds);
        $userIds = $members->pluck('id')->map(fn ($id) => (int) $id)->all();

        $planned = $this->planned->forWindow($window, $projectIds);
        $logged = $this->logged->forWindow($window, $projectIds);
        $available = $this->available->forMembers($userIds, $window);

        $rows = $members->map(function (User $user) use ($planned, $logged, $available) {
            $id = (int) $user->id;
            $capacity = $available[$id] ?? 0.0;
            $plannedHours = round($planned['hours'][$id] ?? 0.0, 2);
            $loggedHours = round($logged[$id] ?? 0.0, 2);

            return [
                'user' => [
                    'id' => $id,
                    'name' => $user->full_name ?: $user->email,
                    'email' => $user->email,
                    'avatar_url' => $user->avatar_url,
                    'initial' => mb_strtoupper(mb_substr($user->full_name ?: $user->email, 0, 1)),
                ],
                'capacity_hours' => $capacity,
                'planned_hours' => $plannedHours,
                'logged_hours' => $loggedHours,
                // Remaining is capacity MINUS PLANNED (§32), and is allowed to go negative —
                // clamping it at zero would hide the overload the column exists to reveal.
                'remaining_hours' => round($capacity - $plannedHours, 2),
                'planned' => $this->status->describe($plannedHours, $capacity),
                'actual' => $this->status->describe($loggedHours, $capacity),
                // §41: what the planned figure does NOT know about, carried beside it so
                // nobody reads a partial total as a complete one.
                'assigned_items' => $planned['items'][$id] ?? 0,
                'unestimated_items' => $planned['unestimated'][$id] ?? 0,
                'undated_items' => $planned['undated'][$id] ?? 0,
                'has_override' => $this->settings->hasOverride($id),
            ];
        })->values()->all();

        return [
            'window' => $window->toArray(),
            'rows' => $rows,
            'summary' => $this->summarize($rows),
            'settings' => [
                'hours_per_day' => $this->settings->hoursPerDay(),
                'weekly_hours' => $this->settings->weeklyHours(),
                'working_days' => $this->settings->workingDays(),
                'near' => $this->settings->nearThreshold(),
                'over' => $this->settings->overThreshold(),
                'high' => $this->settings->highThreshold(),
            ],
        ];
    }

    /**
     * The summary cards (§31).
     *
     * Utilization is computed from the TOTALS, not by averaging each member's percentage. The
     * average of percentages weights a half-time member equally with a full-time one, so a
     * team can read as 100% utilized while a third of its hours sit unused.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function summarize(array $rows): array
    {
        $capacity = round(array_sum(array_column($rows, 'capacity_hours')), 2);
        $planned = round(array_sum(array_column($rows, 'planned_hours')), 2);
        $logged = round(array_sum(array_column($rows, 'logged_hours')), 2);

        $counts = ['near' => 0, 'over' => 0, 'high' => 0];

        foreach ($rows as $row) {
            $key = $row['planned']['key'];
            if (isset($counts[$key])) {
                $counts[$key]++;
            }
        }

        return [
            'members' => count($rows),
            'capacity_hours' => $capacity,
            'planned_hours' => $planned,
            'logged_hours' => $logged,
            'remaining_hours' => round($capacity - $planned, 2),
            'planned' => $this->status->describe($planned, $capacity),
            'actual' => $this->status->describe($logged, $capacity),
            'near_capacity_members' => $counts['near'],
            'over_capacity_members' => $counts['over'],
            'high_workload_members' => $counts['high'],
            'unestimated_items' => array_sum(array_column($rows, 'unestimated_items')),
            'undated_items' => array_sum(array_column($rows, 'undated_items')),
        ];
    }

    /**
     * Active members of the workspace, optionally narrowed to what the viewer may see.
     *
     * @param  array<int, int>|null  $visibleUserIds
     * @return Collection<int, User>
     */
    private function members(?array $visibleUserIds)
    {
        $ids = WorkspaceMembership::query()
            ->where('workspace_id', tenant('id'))
            ->where('status', 'active')
            ->pluck('user_id');

        if ($visibleUserIds !== null) {
            $ids = $ids->intersect($visibleUserIds);
        }

        return User::query()->whereIn('id', $ids)->orderBy('full_name')->get();
    }
}
