<?php

namespace Tests\Feature\Capacity;

use App\Models\EstimateValue;
use App\Models\Project;
use App\Models\ProjectEstimation;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\Workspace;
use App\Models\WorkspaceSettings;
use App\Services\ProjectItemStateProvisioner;
use App\Services\WorkspaceSettingsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Project\ProjectTestCase;

/**
 * Project › Settings › Estimates — Capacity Mapping (work-capacity §7–§10, CAP-007/008/009).
 *
 * Where estimates acquire an hour value. Without it a size project can be estimated to the last
 * XL and still report zero planned capacity, which is the failure this screen exists to prevent.
 */
class CapacityMappingTest extends ProjectTestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private Project $project;

    private User $owner;

    private function setUpProject(bool $capacityEnabled = true): void
    {
        [$this->owner, $this->workspace] = $this->owner();
        tenancy()->initialize($this->workspace);

        $this->project = $this->makeProject($this->owner, $this->workspace);

        // States are provisioned on first use of the work-item screen, not by ProjectCreator —
        // and "is this work complete?" is a question about its state.
        app(ProjectItemStateProvisioner::class)->for($this->project);

        app(WorkspaceSettingsManager::class)->for($this->workspace);
        WorkspaceSettings::query()->first()->forceFill([
            'capacity_enabled' => $capacityEnabled,
            'capacity_hours_per_day' => 8,
            'capacity_working_days' => [1, 2, 3, 4, 5],
        ])->save();
    }

    private function estimation(string $type = ProjectEstimation::TYPE_CATEGORY): ProjectEstimation
    {
        return ProjectEstimation::create([
            'tenant_id' => $this->workspace->id,
            'project_id' => $this->project->id,
            'type' => $type,
            'template' => 'custom',
            'created_by' => $this->owner->id,
        ]);
    }

    private function value(ProjectEstimation $estimation, string $label, array $attrs = []): EstimateValue
    {
        return EstimateValue::create(array_merge([
            'tenant_id' => $this->workspace->id,
            'project_estimation_id' => $estimation->id,
            'label' => $label,
        ], $attrs));
    }

    /** @return array<string, mixed> */
    private function screen(): array
    {
        return $this->actingAs($this->owner)
            ->get("/projects/{$this->project->id}/settings/estimates")
            ->assertOk()
            // `bootstrap` IS this section's payload — the controller matches on the section
            // rather than nesting every section under its own key.
            ->viewData('bootstrap');
    }

    // ---- the working week on this screen -----------------------------------------------------

    public function test_the_screen_states_the_working_day_and_week(): void
    {
        $this->setUpProject();
        $this->estimation();

        $capacity = $this->screen()['capacity'];

        // Shown here rather than only linked to: "16h" is a heavy item against a 40h week and
        // an impossible one against 20h, so the numbers being typed need their frame of
        // reference on the same screen.
        $this->assertTrue($capacity['enabled']);
        $this->assertSame(8.0, $capacity['hoursPerDay']);
        $this->assertSame(40.0, $capacity['weeklyHours']);
        $this->assertSame(5, $capacity['workingDays']);
    }

    public function test_the_working_week_follows_the_workspace_setting(): void
    {
        $this->setUpProject();
        $this->estimation();

        WorkspaceSettings::query()->first()->forceFill([
            'capacity_hours_per_day' => 7.5,
            'capacity_working_days' => [1, 2, 3, 4],
        ])->save();

        $this->assertSame(30.0, $this->screen()['capacity']['weeklyHours']);
    }

    public function test_the_screen_reports_capacity_as_off_when_it_is(): void
    {
        // The screen needs to know which of the two it is: with tracking off, an hours column
        // collects a number that feeds nothing.
        $this->setUpProject(capacityEnabled: false);
        $this->estimation();

        $this->assertFalse($this->screen()['capacity']['enabled']);
    }

    // ---- hours per value ---------------------------------------------------------------------

    public function test_each_value_carries_its_hours(): void
    {
        $this->setUpProject();
        $estimation = $this->estimation();
        $this->value($estimation, 'M', ['capacity_hours' => 8]);
        $this->value($estimation, 'XL');

        $values = collect($this->screen()['estimation']['values'])->keyBy('label');

        $this->assertSame(8.0, $values['M']['hours']);
        // Null, not zero — CAP-D2. An unmapped size is work whose size nobody has stated,
        // which is not the same as work that takes no time.
        $this->assertNull($values['XL']['hours']);
    }

    public function test_a_time_estimate_derives_its_hours_from_its_own_duration(): void
    {
        $this->setUpProject();
        $estimation = $this->estimation(ProjectEstimation::TYPE_TIME);
        $this->value($estimation, '90m', ['duration_minutes' => 90]);

        $value = $this->screen()['estimation']['values'][0];

        // Nothing to configure: asking for the figure a second time invites two answers that
        // are free to disagree.
        $this->assertSame(1.5, $value['hours']);
        $this->assertNull($value['capacity_hours']);
    }

    public function test_hours_can_be_set_on_a_value(): void
    {
        $this->setUpProject();
        $estimation = $this->estimation();
        $value = $this->value($estimation, 'L');

        $this->actingAs($this->owner)
            ->patchJson("/projects/{$this->project->id}/settings/estimation/values/{$value->id}", [
                'capacity_hours' => 16,
            ])->assertOk();

        $this->assertSame('16.00', (string) $value->fresh()->capacity_hours);
    }

    public function test_hours_can_be_cleared_back_to_unmapped(): void
    {
        $this->setUpProject();
        $estimation = $this->estimation();
        $value = $this->value($estimation, 'L', ['capacity_hours' => 16]);

        $this->actingAs($this->owner)
            ->patchJson("/projects/{$this->project->id}/settings/estimation/values/{$value->id}", [
                'capacity_hours' => null,
            ])->assertOk();

        $this->assertNull($value->fresh()->capacity_hours);
    }

    public function test_a_negative_hour_value_is_refused(): void
    {
        $this->setUpProject();
        $estimation = $this->estimation();
        $value = $this->value($estimation, 'L');

        $this->actingAs($this->owner)
            ->patchJson("/projects/{$this->project->id}/settings/estimation/values/{$value->id}", [
                'capacity_hours' => -4,
            ])->assertStatus(422)->assertJsonValidationErrors('capacity_hours');
    }

    public function test_a_value_can_be_added_with_its_hours_in_one_go(): void
    {
        $this->setUpProject();
        $this->estimation();

        $this->actingAs($this->owner)
            ->postJson("/projects/{$this->project->id}/settings/estimation/values", [
                'label' => 'XXL',
                'capacity_hours' => 40,
            ])->assertOk();

        $this->assertSame('40.00', (string) EstimateValue::query()->where('label', 'XXL')->first()->capacity_hours);
    }

    // ---- CAP-D3: changing a mapping re-plans open work, not history ----------------------------

    public function test_changing_a_mapping_re_plans_open_work_items(): void
    {
        $this->setUpProject();
        $estimation = $this->estimation();
        $value = $this->value($estimation, 'L', ['capacity_hours' => 12]);

        $item = WorkItem::create([
            'tenant_id' => $this->workspace->id,
            'project_id' => $this->project->id,
            'title' => 'Payment integration',
            'created_by' => $this->owner->id,
            'estimate_value_id' => $value->id,
        ]);

        $this->assertSame('12.00', (string) $item->fresh()->capacity_hours);

        // §47's example: L reassessed from 12 hours to 16.
        $this->actingAs($this->owner)
            ->patchJson("/projects/{$this->project->id}/settings/estimation/values/{$value->id}", [
                'capacity_hours' => 16,
            ])->assertOk();

        // Work not yet done is planned with today's figure.
        $this->assertSame('16.00', (string) $item->fresh()->capacity_hours);
    }

    public function test_changing_a_mapping_leaves_completed_work_as_it_was_planned(): void
    {
        $this->setUpProject();
        $estimation = $this->estimation();
        $value = $this->value($estimation, 'L', ['capacity_hours' => 12]);

        $done = $this->project->states()->where('group', 'completed')->first();
        $this->assertNotNull($done, 'The project has no completed state to put the item in.');

        $item = WorkItem::create([
            'tenant_id' => $this->workspace->id,
            'project_id' => $this->project->id,
            'title' => 'Shipped last quarter',
            'created_by' => $this->owner->id,
            'estimate_value_id' => $value->id,
            'state_id' => $done?->id,
        ]);

        $this->actingAs($this->owner)
            ->patchJson("/projects/{$this->project->id}/settings/estimation/values/{$value->id}", [
                'capacity_hours' => 16,
            ])->assertOk();

        // §47: changing today's sizing must not silently rewrite what last quarter reported.
        $this->assertSame('12.00', (string) $item->fresh()->capacity_hours);
    }

    // ---- permissions ---------------------------------------------------------------------------

    public function test_a_plain_member_cannot_change_the_mapping(): void
    {
        $this->setUpProject();
        $estimation = $this->estimation();
        $value = $this->value($estimation, 'L');

        $member = $this->member($this->workspace, 'member', 'member@example.com');

        $this->actingAs($member)
            ->patchJson("/projects/{$this->project->id}/settings/estimation/values/{$value->id}", [
                'capacity_hours' => 999,
            ])->assertForbidden();
    }
}
