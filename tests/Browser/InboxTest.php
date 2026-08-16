<?php

use App\Models\InboxNotification;
use App\Services\WorkItemCreator;

/**
 * The Inbox, in a real browser (docs/features/inbox.md).
 */
it('shows the empty state when nothing needs attention', function () {
    [$owner] = e2eWorkspace('inbox-empty');
    $this->actingAs($owner);

    visit('/inbox')
        ->assertSee('Inbox')
        ->assertSee('All')
        ->assertSee('Assigned')
        ->assertSee('Mentions')
        // §23's wording, and proof the Vue root mounted rather than leaving its placeholder.
        ->assertSee('You’re all caught up')
        ->assertNoJavascriptErrors();
});

it('lists an assignment and its unread count', function () {
    [$owner, $workspace, $project] = e2eWorkspace('inbox-list');
    $mate = e2eTeammate($workspace, $project, 'Priya Nair', 'priya-'.uniqid().'@example.com');

    $item = $workspace->run(fn () => app(WorkItemCreator::class)
        ->create($owner, $project, ['title' => 'Configure Stale Ticket Automation']));

    $this->actingAs($owner)->patchJson(
        route('projects.work-items.update', ['project' => $project->id, 'workItem' => $item->id]),
        ['assignee_ids' => [$mate->id]],
    )->assertOk();

    expect(InboxNotification::for($mate->id)->unread()->count())->toBe(1);

    $this->actingAs($mate);
    visit('/inbox')
        ->assertSee('Configure Stale Ticket Automation')
        ->assertSee('assigned this to you')
        ->assertNoJavascriptErrors();
});
