<?php

use App\Models\WorkItem;

/**
 * "New work item", from anywhere (docs/features/quick-create.md).
 *
 * The behaviour under test is a NEGATIVE one — the page must not change — which is precisely
 * what an HTTP test cannot express. Only a browser can tell "opened a modal" from "navigated
 * somewhere that opened a modal".
 */
it('opens the modal in place instead of navigating away', function () {
    [$owner] = e2eWorkspace('quick-create');
    $this->actingAs($owner);

    $page = visit('/inbox');
    $page->assertSee('Inbox')
        ->click('New work item')
        // The modal's own footer button — "Choose a project" is the picker's PLACEHOLDER, and
        // with a single project the modal selects it for you, so that text is already gone.
        ->assertSee('Create work item')
        // Still on the Inbox. Before this, the button left for the project's Work Items.
        ->assertUrlIs(url('/inbox'))
        ->assertNoJavascriptErrors();
});

it('creates a work item without leaving the screen', function () {
    [$owner, , $project] = e2eWorkspace('quick-create-save');
    $this->actingAs($owner);

    $page = visit('/your-work');
    $page->click('New work item')
        // One project, so it is chosen automatically rather than made into a decision.
        ->type('input[placeholder="What needs doing?"]', 'Configure Stale Ticket Automation')
        ->click('Create work item')
        ->assertSee('Open')
        ->assertUrlIs(url('/your-work'))
        ->assertNoJavascriptErrors();

    expect(WorkItem::query()->where('project_id', $project->id)
        ->where('title', 'Configure Stale Ticket Automation')->exists())->toBeTrue();
});
