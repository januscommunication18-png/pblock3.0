<?php

/**
 * Your Work, in a real browser (docs/features/your-work.md).
 *
 * The Summary tab is the one worth opening in a browser: it draws two hand-rolled SVG charts
 * whose classes were missing from the compiled stylesheet the first time round.
 */
it('renders every tab and the summary charts', function () {
    [$owner] = e2eWorkspace('your-work');
    $this->actingAs($owner);

    visit('/your-work')
        ->assertSee('Your work')
        ->assertSee('Summary')
        ->assertSee('Assigned')
        ->assertSee('Created')
        ->assertSee('Subscribed')
        ->assertSee('Activity')
        // The Summary's own content, not just its tab.
        ->assertSee('Overview')
        ->assertSee('Workload')
        ->assertSee('Work items by Priority')
        ->assertSee('Work items by state')
        ->assertNoJavascriptErrors();
});

it('opens the assigned tab', function () {
    [$owner] = e2eWorkspace('your-work-assigned');
    $this->actingAs($owner);

    visit('/your-work/assigned')
        ->assertSee('Nothing here yet')
        ->assertNoJavascriptErrors();
});
