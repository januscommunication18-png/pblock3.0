<?php

use App\Models\WorkspaceSettings;
use App\Services\WorkspaceSettingsManager;

/**
 * Settings › Work Capacity, in a real browser (docs/features/work-capacity.md).
 *
 * The gating is a Vue `v-if`, which an HTTP test cannot see at all — the template string ships
 * in the response either way. This project has already paid for that lesson twice, with icons
 * that never rendered and Tailwind classes that were never compiled.
 */
it('hides the configuration until capacity tracking is enabled', function () {
    [$owner] = e2eWorkspace('capacity-off');
    $this->actingAs($owner);

    visit('/settings/work-capacity')
        ->assertSee('Enable work capacity tracking')
        // Configuring a working week for a report nobody can open is a form with no
        // consequence, and it reads as though it did something.
        ->assertDontSee('Standard working week')
        ->assertDontSee('Capacity thresholds')
        ->assertNoJavascriptErrors();
});

it('reveals the working week and thresholds once enabled', function () {
    [$owner, $workspace] = e2eWorkspace('capacity-on');
    $this->actingAs($owner);

    // Provisioned first: the settings row is created lazily on first access, and a browser
    // test should not be the thing that discovers it is missing.
    $workspace->run(function () use ($workspace) {
        app(WorkspaceSettingsManager::class)->for($workspace);
        WorkspaceSettings::query()->first()->forceFill(['capacity_enabled' => true])->save();
    });

    visit('/settings/work-capacity')
        ->assertSee('Standard working week')
        ->assertSee('Working hours per day')
        ->assertSee('Working days')
        // §15's derived figure, which is the whole point of the two fields above it.
        ->assertSee('Weekly capacity')
        ->assertSee('40h')
        ->assertSee('Capacity thresholds')
        ->assertNoJavascriptErrors();
});
