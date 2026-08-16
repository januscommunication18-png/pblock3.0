<?php

use App\Models\EstimateValue;
use App\Models\ProjectEstimation;
use App\Models\WorkspaceSettings;
use App\Services\WorkspaceSettingsManager;

/**
 * Project › Settings › Estimates — Capacity Mapping, in a real browser
 * (docs/features/work-capacity.md §7).
 *
 * The working-week header and the per-value hours field are both behind a Vue `v-if`, which an
 * HTTP test cannot see: the template string ships in the response whether or not it renders.
 */

/** A project with a size-based estimation system, and capacity either on or off. */
function capacityProject(string $slug, bool $enabled): array
{
    [$owner, $workspace, $project] = e2eWorkspace($slug);

    $workspace->run(function () use ($workspace, $project, $owner, $enabled) {
        app(WorkspaceSettingsManager::class)->for($workspace);
        WorkspaceSettings::query()->first()->forceFill([
            'capacity_enabled' => $enabled,
            'capacity_hours_per_day' => 8,
            'capacity_working_days' => [1, 2, 3, 4, 5],
        ])->save();

        // The value editor only renders once the project's own Estimation feature is on —
        // without this the screen shows "Estimation is disabled" and nothing below it.
        $project->forceFill([
            'features' => array_merge($project->features ?? [], ['estimates' => true]),
        ])->save();

        $estimation = ProjectEstimation::create([
            'tenant_id' => $workspace->id,
            'project_id' => $project->id,
            'type' => ProjectEstimation::TYPE_CATEGORY,
            'template' => 'tshirt',
            'created_by' => $owner->id,
        ]);

        foreach ([['S', 4], ['M', 8], ['L', null]] as $i => [$label, $hours]) {
            EstimateValue::create([
                'tenant_id' => $workspace->id,
                'project_estimation_id' => $estimation->id,
                'label' => $label,
                'capacity_hours' => $hours,
                'sort_order' => $i + 1,
            ]);
        }
    });

    return [$owner, $project];
}

it('shows the working week and an hours field per value when capacity is on', function () {
    [$owner, $project] = capacityProject('cap-map-on', true);
    $this->actingAs($owner);

    visit("/projects/{$project->id}/settings/estimates")
        ->assertSee('Working day')
        ->assertSee('Working week')
        // §15's totals, so the hours being typed have their frame of reference on screen.
        ->assertSee('40h')
        ->assertSee('Give each value the working hours it represents')
        // §41 at the point somebody can act on it — L has no hours yet.
        ->assertSee('1 value has no hours yet')
        ->assertNoJavascriptErrors();
});

it('asks for no mapping when capacity tracking is off', function () {
    [$owner, $project] = capacityProject('cap-map-off', false);
    $this->actingAs($owner);

    // An hours column with nothing to feed collects a number for no reason.
    visit("/projects/{$project->id}/settings/estimates")
        // Something specific to the value editor, so this cannot pass on a page that failed
        // to render it — "S" alone matches Settings, States and half the left nav.
        ->assertSee('One estimation system is active per project')
        ->assertDontSee('Working week')
        ->assertDontSee('Give each value the working hours it represents')
        ->assertNoJavascriptErrors();
});
