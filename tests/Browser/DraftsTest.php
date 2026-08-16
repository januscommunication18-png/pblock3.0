<?php

use App\Models\WorkItem;

/**
 * Drafts, in a real browser (docs/features/drafts.md).
 *
 * The HTTP suite already proves the API. What it cannot prove is that the SCREEN works, and
 * this screen is where the bugs actually shipped: an editor that mounted invisible, pickers
 * that drew no glyphs, a `hidden` class Font Awesome silently overrode. None of those failed
 * a single HTTP assertion.
 */
it('mounts the screen with its controls drawn', function () {
    [$owner] = e2eWorkspace('drafts');
    $this->actingAs($owner);

    visit('/drafts')
        ->assertSee('Drafts')
        ->assertSee('New draft')
        // The Vue root booted. A failed boot leaves the "Loading drafts…" placeholder, and
        // the server would still have answered 200.
        ->assertSee('No drafts yet')
        ->assertNoJavascriptErrors();
});

it('writes a draft that survives the page', function () {
    [$owner] = e2eWorkspace('drafts-write');
    $this->actingAs($owner);

    visit('/drafts')
        ->click('New draft')
        ->type('input[placeholder="What needs doing?"]', 'Chase the invoice PDF bug')
        // Leaving the title is one of the five save triggers.
        ->click('Discard')
        ->assertNoJavascriptErrors();

    // Server-side is the only proof that outlives the browser.
    expect(WorkItem::query()->withoutGlobalScopes()
        ->where('is_draft', true)
        ->where('title', 'Chase the invoice PDF bug')
        ->exists())->toBeTrue();
});
