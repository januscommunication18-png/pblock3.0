<?php

namespace Tests\Feature\Settings;

use App\Models\WorkspaceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Settings › Work Capacity (§12–§15, §29, CAP-001/002/018/020).
 */
class WorkCapacitySettingsTest extends SettingsTestCase
{
    use RefreshDatabase;

    public function test_the_screen_opens_with_the_defaults_a_workspace_starts_on(): void
    {
        [$owner] = $this->owner();

        $bootstrap = $this->actingAs($owner)
            ->get('/settings/work-capacity')
            ->assertOk()
            ->viewData('bootstrap');

        $this->assertFalse($bootstrap['enabled']);
        $this->assertSame(8.0, $bootstrap['hoursPerDay']);
        $this->assertSame([1, 2, 3, 4, 5], $bootstrap['workingDays']);
        // §15's example, computed server-side so the screen and the report cannot disagree.
        $this->assertSame(40.0, $bootstrap['weeklyHours']);
        $this->assertSame(['near' => 90, 'over' => 100, 'high' => 110], $bootstrap['thresholds']);
    }

    public function test_capacity_tracking_can_be_switched_on_and_off(): void
    {
        [$owner] = $this->owner();

        $this->actingAs($owner)->postJson('/settings/work-capacity/toggle', ['enabled' => true])
            ->assertOk()->assertJsonPath('enabled', true);

        $this->assertTrue((bool) WorkspaceSettings::query()->first()->capacity_enabled);

        // Unlike Teamspaces, this one is reversible — nothing is destroyed by turning it off.
        $this->actingAs($owner)->postJson('/settings/work-capacity/toggle', ['enabled' => false])
            ->assertOk()->assertJsonPath('enabled', false);
    }

    public function test_the_working_week_saves_and_the_weekly_total_follows_it(): void
    {
        [$owner] = $this->owner();

        $response = $this->actingAs($owner)->patchJson('/settings/work-capacity', [
            'hours_per_day' => 7.5,
            'working_days' => [1, 2, 3, 4],
            'near_threshold' => 85,
            'over_threshold' => 100,
            'high_threshold' => 120,
        ])->assertOk();

        // Compared as a float: a whole number of hours round-trips through JSON as an int,
        // and the assertion is about the arithmetic, not the encoding.
        $this->assertSame(30.0, (float) $response->json('weeklyHours'));

        $settings = WorkspaceSettings::query()->first();

        $this->assertSame('7.50', (string) $settings->capacity_hours_per_day);
        $this->assertSame([1, 2, 3, 4], $settings->capacity_working_days);
        $this->assertSame(85, $settings->capacity_near_threshold);
    }

    public function test_a_decimal_working_day_is_accepted(): void
    {
        [$owner] = $this->owner();

        // §13 asks for 7.5 and 6 explicitly — an integer-only field would silently round
        // somebody's real working day to one they never agreed to.
        $this->actingAs($owner)->patchJson('/settings/work-capacity', $this->payload(['hours_per_day' => 7.5]))
            ->assertOk();
    }

    public function test_an_impossible_working_day_is_refused(): void
    {
        [$owner] = $this->owner();

        $this->actingAs($owner)->patchJson('/settings/work-capacity', $this->payload(['hours_per_day' => 30]))
            ->assertStatus(422)->assertJsonValidationErrors('hours_per_day');

        $this->actingAs($owner)->patchJson('/settings/work-capacity', $this->payload(['hours_per_day' => 0]))
            ->assertStatus(422)->assertJsonValidationErrors('hours_per_day');
    }

    public function test_a_week_with_no_working_days_is_refused(): void
    {
        [$owner] = $this->owner();

        // Zero working days means zero capacity, which makes every utilization undefined —
        // the report would read as broken rather than as configured that way.
        $this->actingAs($owner)->patchJson('/settings/work-capacity', $this->payload(['working_days' => []]))
            ->assertStatus(422)->assertJsonValidationErrors('working_days');
    }

    public function test_thresholds_must_climb(): void
    {
        [$owner] = $this->owner();

        // Out of order, a band becomes unreachable: with "over" below "near", nothing is ever
        // Near Capacity and the status simply stops appearing, with nothing to explain why.
        $this->actingAs($owner)->patchJson('/settings/work-capacity', $this->payload([
            'near_threshold' => 100,
            'over_threshold' => 90,
        ]))->assertStatus(422)->assertJsonValidationErrors('near_threshold');
    }

    public function test_duplicate_working_days_collapse_rather_than_inflating_capacity(): void
    {
        [$owner] = $this->owner();

        // Monday sent twice would otherwise count twice, and weekly capacity is days × hours.
        $response = $this->actingAs($owner)->patchJson('/settings/work-capacity', $this->payload([
            'working_days' => [1, 1, 2, 2, 3],
        ]))->assertOk();

        $this->assertSame(24.0, (float) $response->json('weeklyHours'));
        $this->assertSame([1, 2, 3], $response->json('workingDays'));
    }

    public function test_only_owners_and_admins_may_configure_capacity(): void
    {
        [, $workspace] = $this->owner();
        $member = $this->member($workspace, 'member', 'member@example.com');

        $this->actingAs($member)->get('/settings/work-capacity')->assertForbidden();
        $this->actingAs($member)->patchJson('/settings/work-capacity', $this->payload())->assertForbidden();
        $this->actingAs($member)->postJson('/settings/work-capacity/toggle', ['enabled' => true])->assertForbidden();
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'hours_per_day' => 8,
            'working_days' => [1, 2, 3, 4, 5],
            'near_threshold' => 90,
            'over_threshold' => 100,
            'high_threshold' => 110,
        ], $overrides);
    }
}
