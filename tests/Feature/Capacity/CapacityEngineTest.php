<?php

namespace Tests\Feature\Capacity;

use App\Models\EstimateValue;
use App\Models\MemberCapacity;
use App\Models\Project;
use App\Models\ProjectEstimation;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkItemWorklog;
use App\Models\Workspace;
use App\Models\WorkspaceSettings;
use App\Services\Capacity\CapacityStatus;
use App\Services\Capacity\CapacityWindow;
use App\Services\Capacity\TeamCapacityReport;
use App\Services\WorkspaceSettingsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Project\ProjectTestCase;

/**
 * The capacity engine (docs/features/work-capacity.md).
 *
 * Most of these reproduce the worked examples from the requirements document verbatim. That is
 * deliberate: the arithmetic is the feature, and a spec that states "36 ÷ 40 = 90%" is a test
 * somebody already wrote — leaving it unasserted means the one number managers act on is the
 * one number nothing checks.
 */
class CapacityEngineTest extends ProjectTestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private Project $project;

    private User $owner;

    /** The Monday–Sunday week every example below is measured over. */
    private function week(): CapacityWindow
    {
        return new CapacityWindow('2026-09-07', '2026-09-13');
    }

    private function setUpWorkspace(): void
    {
        [$this->owner, $this->workspace] = $this->owner();
        tenancy()->initialize($this->workspace);

        $this->project = $this->makeProject($this->owner, $this->workspace);

        app(WorkspaceSettingsManager::class)->for($this->workspace);
        WorkspaceSettings::query()->first()->forceFill([
            'capacity_enabled' => true,
            'capacity_hours_per_day' => 8,
            'capacity_working_days' => [1, 2, 3, 4, 5],
        ])->save();
    }

    /** An estimation system on the project, plus its values keyed by label. */
    private function estimation(string $type, array $values): array
    {
        $estimation = ProjectEstimation::create([
            'tenant_id' => $this->workspace->id,
            'project_id' => $this->project->id,
            'type' => $type,
            'template' => 'custom',
            'created_by' => $this->owner->id,
        ]);

        $made = [];

        foreach ($values as $label => $attrs) {
            $made[$label] = EstimateValue::create(array_merge([
                'tenant_id' => $this->workspace->id,
                'project_estimation_id' => $estimation->id,
                'label' => (string) $label,
            ], $attrs));
        }

        return $made;
    }

    private function item(?EstimateValue $estimate, array $assignees, ?string $start = '2026-09-08', ?string $due = '2026-09-08', array $extra = []): WorkItem
    {
        $item = WorkItem::create(array_merge([
            'tenant_id' => $this->workspace->id,
            'project_id' => $this->project->id,
            'title' => 'Work item',
            'created_by' => $this->owner->id,
            'estimate_value_id' => $estimate?->id,
            'start_date' => $start,
            'due_date' => $due,
        ], $extra));

        $item->assignees()->sync(array_map(fn (User $u) => $u->id, $assignees));

        return $item->fresh();
    }

    private function report(?array $visible = null): array
    {
        return app(TeamCapacityReport::class)->build($this->week(), null, $visible);
    }

    private function rowFor(array $report, User $user): array
    {
        foreach ($report['rows'] as $row) {
            if ($row['user']['id'] === $user->id) {
                return $row;
            }
        }

        $this->fail("No capacity row for {$user->email}");
    }

    // ---- §15: weekly capacity is derived ------------------------------------------------

    public function test_weekly_capacity_is_daily_hours_times_working_days(): void
    {
        $this->setUpWorkspace();

        // §15's own example: 8 × 5 = 40.
        $this->assertSame(40.0, $this->rowFor($this->report(), $this->owner)['capacity_hours']);
    }

    public function test_a_shorter_working_week_reduces_capacity(): void
    {
        $this->setUpWorkspace();
        WorkspaceSettings::query()->first()->forceFill([
            'capacity_hours_per_day' => 7.5,
            'capacity_working_days' => [1, 2, 3, 4],
        ])->save();

        $this->assertSame(30.0, $this->rowFor($this->report(), $this->owner)['capacity_hours']);
    }

    // ---- §18 / §19 / §24: the worked examples --------------------------------------------

    public function test_section_18_hour_estimates_reach_ninety_percent(): void
    {
        $this->setUpWorkspace();
        $sarah = $this->member($this->workspace, 'member', 'sarah@example.com');

        $v = $this->estimation(ProjectEstimation::TYPE_TIME, [
            '8h' => ['duration_minutes' => 480],
            '12h' => ['duration_minutes' => 720],
            '16h' => ['duration_minutes' => 960],
        ]);

        $this->item($v['8h'], [$sarah]);
        $this->item($v['12h'], [$sarah]);
        $this->item($v['16h'], [$sarah]);

        $row = $this->rowFor($this->report(), $sarah);

        $this->assertSame(36.0, $row['planned_hours']);
        $this->assertSame(40.0, $row['capacity_hours']);
        $this->assertSame(90.0, $row['planned']['value']);
        $this->assertSame('Near Capacity', $row['planned']['label']);
        $this->assertSame(4.0, $row['remaining_hours']);
    }

    public function test_section_19_size_estimates_convert_through_their_mapping(): void
    {
        $this->setUpWorkspace();
        $sarah = $this->member($this->workspace, 'member', 'sarah@example.com');

        // §9's mapping: sizes have no inherent duration, so capacity_hours is what makes them
        // summable at all.
        $v = $this->estimation(ProjectEstimation::TYPE_CATEGORY, [
            'S' => ['capacity_hours' => 4],
            'M' => ['capacity_hours' => 8],
            'L' => ['capacity_hours' => 16],
        ]);

        $this->item($v['M'], [$sarah]);
        $this->item($v['L'], [$sarah]);
        $this->item($v['M'], [$sarah]);

        $row = $this->rowFor($this->report(), $sarah);

        $this->assertSame(32.0, $row['planned_hours']);
        $this->assertSame(80.0, $row['planned']['value']);
        $this->assertSame('Normal', $row['planned']['label']);
    }

    public function test_section_8_point_estimates_convert_through_their_mapping(): void
    {
        $this->setUpWorkspace();
        $sarah = $this->member($this->workspace, 'member', 'sarah@example.com');

        $v = $this->estimation(ProjectEstimation::TYPE_POINTS, [
            '5' => ['numeric_value' => 5, 'capacity_hours' => 10],
        ]);

        // §8: a work item estimated at 5 points contributes 10 planned hours.
        $this->item($v['5'], [$sarah]);

        $this->assertSame(10.0, $this->rowFor($this->report(), $sarah)['planned_hours']);
    }

    // ---- CAP-D2: unmapped is not zero ------------------------------------------------------

    public function test_an_unmapped_size_contributes_nothing_and_is_counted_as_unestimated(): void
    {
        $this->setUpWorkspace();
        $sarah = $this->member($this->workspace, 'member', 'sarah@example.com');

        $v = $this->estimation(ProjectEstimation::TYPE_CATEGORY, [
            'M' => ['capacity_hours' => 8],
            'XL' => [],  // nobody has said what XL is worth yet
        ]);

        $this->item($v['M'], [$sarah]);
        $this->item($v['XL'], [$sarah]);

        $row = $this->rowFor($this->report(), $sarah);

        // The unmapped item must not be silently worth 0h — the total would look complete.
        $this->assertSame(8.0, $row['planned_hours']);
        $this->assertSame(1, $row['unestimated_items']);
        $this->assertSame(2, $row['assigned_items']);
    }

    public function test_work_with_no_estimate_at_all_is_counted_not_absorbed(): void
    {
        $this->setUpWorkspace();
        $sarah = $this->member($this->workspace, 'member', 'sarah@example.com');

        $this->item(null, [$sarah]);

        $row = $this->rowFor($this->report(), $sarah);

        $this->assertSame(0.0, $row['planned_hours']);
        $this->assertSame(1, $row['unestimated_items']);
    }

    // ---- CAP-D5: undated work ---------------------------------------------------------------

    public function test_undated_work_cannot_be_placed_in_a_week_and_says_so(): void
    {
        $this->setUpWorkspace();
        $sarah = $this->member($this->workspace, 'member', 'sarah@example.com');

        $v = $this->estimation(ProjectEstimation::TYPE_CATEGORY, ['M' => ['capacity_hours' => 8]]);
        $this->item($v['M'], [$sarah], start: null, due: null);

        $row = $this->rowFor($this->report(), $sarah);

        $this->assertSame(0.0, $row['planned_hours']);
        $this->assertSame(1, $row['undated_items']);
    }

    // ---- CAP-D4: spreading ------------------------------------------------------------------

    public function test_a_multi_week_item_contributes_only_the_part_inside_the_window(): void
    {
        $this->setUpWorkspace();
        $sarah = $this->member($this->workspace, 'member', 'sarah@example.com');

        $v = $this->estimation(ProjectEstimation::TYPE_CATEGORY, ['XL' => ['capacity_hours' => 28]]);

        // 14 calendar days at 2h/day; exactly 7 of them are the reporting week.
        $this->item($v['XL'], [$sarah], start: '2026-09-07', due: '2026-09-20');

        // Charging the whole 28h to both weeks would report 70% utilization in each — the
        // person would look busy for a fortnight on one item's worth of work.
        $this->assertSame(14.0, $this->rowFor($this->report(), $sarah)['planned_hours']);
    }

    public function test_work_entirely_outside_the_window_does_not_count(): void
    {
        $this->setUpWorkspace();
        $sarah = $this->member($this->workspace, 'member', 'sarah@example.com');

        $v = $this->estimation(ProjectEstimation::TYPE_CATEGORY, ['M' => ['capacity_hours' => 8]]);
        $this->item($v['M'], [$sarah], start: '2026-10-05', due: '2026-10-05');

        $row = $this->rowFor($this->report(), $sarah);

        $this->assertSame(0.0, $row['planned_hours']);
        $this->assertSame(0, $row['assigned_items']);
    }

    // ---- §22: multiple assignees ------------------------------------------------------------

    public function test_two_assignees_split_the_hours_equally(): void
    {
        $this->setUpWorkspace();
        $sarah = $this->member($this->workspace, 'member', 'sarah@example.com');
        $john = $this->member($this->workspace, 'member', 'john@example.com');

        $v = $this->estimation(ProjectEstimation::TYPE_TIME, ['12h' => ['duration_minutes' => 720]]);
        $this->item($v['12h'], [$sarah, $john]);

        $report = $this->report();

        $this->assertSame(6.0, $this->rowFor($report, $sarah)['planned_hours']);
        $this->assertSame(6.0, $this->rowFor($report, $john)['planned_hours']);
    }

    // ---- §23 / CAP-D6: no double counting ---------------------------------------------------

    public function test_a_parent_and_its_estimated_children_are_never_both_counted(): void
    {
        $this->setUpWorkspace();
        $sarah = $this->member($this->workspace, 'member', 'sarah@example.com');

        $v = $this->estimation(ProjectEstimation::TYPE_TIME, [
            '16h' => ['duration_minutes' => 960],
            '6h' => ['duration_minutes' => 360],
            '10h' => ['duration_minutes' => 600],
        ]);

        $parent = $this->item($v['16h'], [$sarah]);
        $this->item($v['6h'], [$sarah], extra: ['parent_id' => $parent->id]);
        $this->item($v['10h'], [$sarah], extra: ['parent_id' => $parent->id]);

        // 16 (parent) + 16 (children) = 32 would be the same work counted twice.
        $this->assertSame(16.0, $this->rowFor($this->report(), $sarah)['planned_hours']);
    }

    public function test_a_parent_whose_children_are_unestimated_still_counts_itself(): void
    {
        $this->setUpWorkspace();
        $sarah = $this->member($this->workspace, 'member', 'sarah@example.com');

        $v = $this->estimation(ProjectEstimation::TYPE_TIME, ['16h' => ['duration_minutes' => 960]]);

        $parent = $this->item($v['16h'], [$sarah]);
        $this->item(null, [$sarah], extra: ['parent_id' => $parent->id]);

        // The children say nothing about size, so the parent's estimate is still the only
        // statement of how big this work is. Ignoring it would report 0h for real work.
        $this->assertSame(16.0, $this->rowFor($this->report(), $sarah)['planned_hours']);
    }

    // ---- §20: mixed estimation types across projects ----------------------------------------

    public function test_section_20_normalizes_hours_points_and_sizes_into_one_total(): void
    {
        $this->setUpWorkspace();
        $sarah = $this->member($this->workspace, 'member', 'sarah@example.com');

        $time = $this->estimation(ProjectEstimation::TYPE_TIME, ['8h' => ['duration_minutes' => 480]]);
        $this->item($time['8h'], [$sarah]);

        // A second and third project, each estimating in its own currency.
        $helpDesk = $this->makeProject($this->owner, $this->workspace, ['name' => 'Help Desk', 'identifier' => 'HD']);
        $mobile = $this->makeProject($this->owner, $this->workspace, ['name' => 'Mobile App', 'identifier' => 'MOB']);

        $original = $this->project;

        $this->project = $helpDesk;
        $points = $this->estimation(ProjectEstimation::TYPE_POINTS, ['5' => ['numeric_value' => 5, 'capacity_hours' => 10]]);
        $this->item($points['5'], [$sarah]);

        $this->project = $mobile;
        $sizes = $this->estimation(ProjectEstimation::TYPE_CATEGORY, ['L' => ['capacity_hours' => 16]]);
        $this->item($sizes['L'], [$sarah]);

        $this->project = $original;

        // §20: 8h + 10h + 16h = 34h, regardless of how each project chose to express it.
        $this->assertSame(34.0, $this->rowFor($this->report(), $sarah)['planned_hours']);
    }

    // ---- §25 / CAP-015: actual hours ---------------------------------------------------------

    public function test_logged_hours_drive_actual_utilization_independently_of_planned(): void
    {
        $this->setUpWorkspace();
        $sarah = $this->member($this->workspace, 'member', 'sarah@example.com');

        $v = $this->estimation(ProjectEstimation::TYPE_TIME, ['32h' => ['duration_minutes' => 1920]]);
        $item = $this->item($v['32h'], [$sarah]);

        WorkItemWorklog::create([
            'tenant_id' => $this->workspace->id,
            'project_id' => $this->project->id,
            'work_item_id' => $item->id,
            'user_id' => $sarah->id,
            'created_by' => $sarah->id,
            'work_date' => '2026-09-09',
            'minutes_logged' => 41 * 60,
        ]);

        $row = $this->rowFor($this->report(), $sarah);

        // §25's example: estimated 32h, logged 41h — over on actual while planned is only 80%.
        $this->assertSame(32.0, $row['planned_hours']);
        $this->assertSame(41.0, $row['logged_hours']);
        $this->assertSame(80.0, $row['planned']['value']);
        $this->assertSame(102.5, $row['actual']['value']);
        $this->assertSame('Normal', $row['planned']['label']);
        $this->assertSame('Over Capacity', $row['actual']['label']);
    }

    public function test_worklogs_outside_the_window_are_not_counted(): void
    {
        $this->setUpWorkspace();
        $sarah = $this->member($this->workspace, 'member', 'sarah@example.com');
        $item = $this->item(null, [$sarah]);

        WorkItemWorklog::create([
            'tenant_id' => $this->workspace->id,
            'project_id' => $this->project->id,
            'work_item_id' => $item->id,
            'user_id' => $sarah->id,
            'work_date' => '2026-08-01',
            'minutes_logged' => 600,
        ]);

        $this->assertSame(0.0, $this->rowFor($this->report(), $sarah)['logged_hours']);
    }

    // ---- §28: threshold boundaries ------------------------------------------------------------

    public function test_the_status_boundaries_fall_exactly_where_section_28_says(): void
    {
        $this->setUpWorkspace();
        $status = app(CapacityStatus::class);

        // 0–89 Normal · 90–99 Near · 100–109 Over · 110+ High.
        $this->assertSame('Normal', $status->label($status->classify(89.9)));
        $this->assertSame('Near Capacity', $status->label($status->classify(90)));
        $this->assertSame('Near Capacity', $status->label($status->classify(99.9)));
        $this->assertSame('Over Capacity', $status->label($status->classify(100)));
        $this->assertSame('Over Capacity', $status->label($status->classify(109.9)));
        $this->assertSame('High Workload', $status->label($status->classify(110)));
    }

    public function test_a_member_with_no_working_days_reports_no_utilization_rather_than_infinity(): void
    {
        $this->setUpWorkspace();
        $status = app(CapacityStatus::class);

        // Not 0%, not 100% — dividing by zero to reach either would put a fabricated number
        // on a screen people make staffing decisions from.
        $this->assertNull($status->utilization(10, 0));
        $this->assertNull($status->classify(null));
        $this->assertSame('Not scheduled', $status->label(null));
    }

    // ---- §16: member overrides -----------------------------------------------------------------

    public function test_a_member_override_replaces_the_default_for_that_member_only(): void
    {
        $this->setUpWorkspace();
        $amy = $this->member($this->workspace, 'member', 'amy@example.com');

        MemberCapacity::create([
            'tenant_id' => $this->workspace->id,
            'user_id' => $amy->id,
            'hours_per_day' => 4,
        ]);

        $report = $this->report();

        // §16's table: Amy 4h/day = 20h/week, while everybody else stays on the default.
        $this->assertSame(20.0, $this->rowFor($report, $amy)['capacity_hours']);
        $this->assertTrue($this->rowFor($report, $amy)['has_override']);
        $this->assertSame(40.0, $this->rowFor($report, $this->owner)['capacity_hours']);
        $this->assertFalse($this->rowFor($report, $this->owner)['has_override']);
    }

    // ---- §31: the summary ----------------------------------------------------------------------

    public function test_the_summary_totals_hours_rather_than_averaging_percentages(): void
    {
        $this->setUpWorkspace();
        $sarah = $this->member($this->workspace, 'member', 'sarah@example.com');

        MemberCapacity::create([
            'tenant_id' => $this->workspace->id,
            'user_id' => $sarah->id,
            'hours_per_day' => 2,   // 10h week
        ]);

        $v = $this->estimation(ProjectEstimation::TYPE_TIME, ['10h' => ['duration_minutes' => 600]]);
        $this->item($v['10h'], [$sarah]);   // Sarah: 10/10 = 100%

        $summary = $this->report()['summary'];

        // Owner 0/40 and Sarah 10/10. Averaging the two percentages gives 50%; the truth is
        // 10 hours planned against 50 available, which is 20%.
        $this->assertSame(50.0, $summary['capacity_hours']);
        $this->assertSame(10.0, $summary['planned_hours']);
        $this->assertSame(20.0, $summary['planned']['value']);
        $this->assertSame(1, $summary['over_capacity_members']);
    }

    // ---- CAP-D3: the snapshot -------------------------------------------------------------------

    public function test_setting_an_estimate_snapshots_its_hours_onto_the_work_item(): void
    {
        $this->setUpWorkspace();
        $v = $this->estimation(ProjectEstimation::TYPE_CATEGORY, ['L' => ['capacity_hours' => 16]]);

        $item = $this->item($v['L'], [$this->owner]);

        $this->assertSame('16.00', (string) $item->capacity_hours);
    }

    public function test_changing_the_estimate_moves_the_snapshot_with_it(): void
    {
        $this->setUpWorkspace();
        $v = $this->estimation(ProjectEstimation::TYPE_CATEGORY, [
            'M' => ['capacity_hours' => 8],
            'L' => ['capacity_hours' => 16],
        ]);

        // §35: M → L must recalculate, not leave the old figure in place.
        $item = $this->item($v['M'], [$this->owner]);
        $item->forceFill(['estimate_value_id' => $v['L']->id])->save();

        $this->assertSame('16.00', (string) $item->fresh()->capacity_hours);
    }

    public function test_clearing_the_estimate_clears_the_snapshot(): void
    {
        $this->setUpWorkspace();
        $v = $this->estimation(ProjectEstimation::TYPE_CATEGORY, ['M' => ['capacity_hours' => 8]]);

        $item = $this->item($v['M'], [$this->owner]);
        $item->forceFill(['estimate_value_id' => null])->save();

        $this->assertNull($item->fresh()->capacity_hours);
    }

    // ---- §46: who sees whom -----------------------------------------------------------------------

    public function test_a_report_can_be_narrowed_to_one_person(): void
    {
        $this->setUpWorkspace();
        $sarah = $this->member($this->workspace, 'member', 'sarah@example.com');

        $report = $this->report(visible: [$sarah->id]);

        $this->assertCount(1, $report['rows']);
        $this->assertSame($sarah->id, $report['rows'][0]['user']['id']);
    }
}
