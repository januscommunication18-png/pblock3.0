<?php

use App\Models\WikiCollection;
use App\Models\WikiCollectionGroup;
use App\Models\WikiLabel;
use App\Models\WikiPage;
use App\Models\WorkspaceSettings;
use App\Services\WorkspaceSettingsManager;

/**
 * Add page, in a real browser (docs/features/wiki.md).
 *
 * The editor is <pg-editor> from projects/page-editor.js — a definition object that must be
 * REGISTERED on this screen's Vue app. It was not, and an unregistered component renders
 * nothing at all: the toolbar and the writing surface were simply absent, with no error.
 * An HTTP test cannot see that, because the template string ships either way.
 */
function wikiCollection(string $slug): array
{
    [$owner, $workspace] = e2eWorkspace($slug);

    $collection = $workspace->run(function () use ($workspace, $owner) {
        app(WorkspaceSettingsManager::class)->for($workspace);
        WorkspaceSettings::query()->first()->forceFill(['wiki_enabled' => true])->save();

        return WikiCollection::create([
            'tenant_id' => $workspace->id, 'name' => 'Help Desk Software',
            'visibility' => 'public', 'created_by' => $owner->id, 'position' => 1,
        ]);
    });

    return [$owner, $collection];
}

it('offers the Add page modal from the collection', function () {
    [$owner, $collection] = wikiCollection('wiki-add-page');
    $this->actingAs($owner);

    visit("/wiki/collections/{$collection->id}")
        ->assertSee('No pages yet')
        ->press('Add page')
        ->assertSee('Page name')
        ->assertSee('Continue')
        ->assertNoJavascriptErrors();
});

it('renders the same editor Project Pages use', function () {
    [$owner, $collection] = wikiCollection('wiki-editor');

    $page = WikiPage::create([
        'tenant_id' => $collection->tenant_id,
        'wiki_collection_id' => $collection->id,
        'title' => 'Escalation process',
        'created_by' => $owner->id, 'updated_by' => $owner->id, 'position' => 1,
    ]);

    $this->actingAs($owner);

    // `.jodit-container` is the editor actually having mounted — the check that would have
    // caught the unregistered component.
    // `.jodit-container` is the editor actually having mounted — the check that would have
    // caught the unregistered component. `.pb-page-toolbar` is the full-width toolbar host
    // that makes this read like a document rather than a form field.
    visit("/wiki/collections/{$collection->id}/pages/{$page->id}")
        ->assertPresent('.jodit-container')
        ->assertPresent('.pb-page-toolbar')
        ->assertSee('Help Desk Software')
        ->assertSee('Escalation process')
        ->assertNoJavascriptErrors();
});

it('keeps the toolbar visible while the document scrolls', function () {
    [$owner, $collection] = wikiCollection('wiki-sticky');

    $body = '';
    for ($i = 1; $i <= 40; $i++) {
        $body .= "<h2>Section {$i}</h2><p>Ring the on-call engineer after 30 minutes.</p>";
    }

    $page = WikiPage::create([
        'tenant_id' => $collection->tenant_id,
        'wiki_collection_id' => $collection->id,
        'title' => 'Escalation process', 'content' => $body,
        'created_by' => $owner->id, 'updated_by' => $owner->id, 'position' => 1,
    ]);

    $this->actingAs($owner);

    // The formatting controls are useless the moment they scroll away from the text they
    // format. Jodit's own sticky mode is off — page-editor.js turns it off because it floats
    // the bar against the wrong container — so the pinning is CSS, and CSS is what this checks.
    $view = visit("/wiki/collections/{$collection->id}/pages/{$page->id}");

    $view->script('(document.querySelector(".pb-page")||document.scrollingElement).scrollTop = 1400');

    // Its POSITION is the property that matters, not merely that it exists: a toolbar that has
    // scrolled off the top of its scroller is still present and still useless.
    $offset = $view->script(
        'JSON.stringify((function () {'
        .'  var bar = document.querySelector(".pb-page-toolbar");'
        .'  var box = document.querySelector(".pb-page");'
        .'  if (!bar || !box) return -1;'
        .'  return Math.round(bar.getBoundingClientRect().top - box.getBoundingClientRect().top);'
        .'})())'
    );

    $top = (int) json_decode(is_array($offset) ? end($offset) : $offset);

    expect($top)->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(2);

    $view->assertNoJavascriptErrors();
});

it('lists pages as a table with every column', function () {
    [$owner, $collection] = wikiCollection('wiki-table');

    $label = WikiLabel::create([
        'tenant_id' => $collection->tenant_id, 'name' => 'Runbook', 'color' => '#2563EB', 'position' => 1,
    ]);

    $parent = WikiPage::create([
        'tenant_id' => $collection->tenant_id, 'wiki_collection_id' => $collection->id,
        'title' => 'Escalation process', 'created_by' => $owner->id,
        'updated_by' => $owner->id, 'position' => 1,
    ]);
    $parent->labels()->sync([$label->id]);

    WikiPage::create([
        'tenant_id' => $collection->tenant_id, 'wiki_collection_id' => $collection->id,
        'title' => 'Severity levels', 'parent_id' => $parent->id,
        'created_by' => $owner->id, 'updated_by' => $owner->id, 'position' => 2,
    ]);

    $this->actingAs($owner);

    visit("/wiki/collections/{$collection->id}")
        ->assertSee('Page name')
        ->assertSee('Owner')
        ->assertSee('Nested pages')
        ->assertSee('Label')
        ->assertSee('Last activity')
        ->assertSee('Runbook')
        // Structure is shown by NESTING now, not by naming the parent in the row — so the
        // child lives inside its parent's list rather than repeating where it sits.
        ->assertPresent('[data-parent="'.$parent->id.'"] [data-page]')
        ->assertNoJavascriptErrors();
});

it('offers Edit, Copy link and Remove from the row menu', function () {
    [$owner, $collection] = wikiCollection('wiki-rowmenu');

    WikiPage::create([
        'tenant_id' => $collection->tenant_id, 'wiki_collection_id' => $collection->id,
        'title' => 'Escalation process', 'created_by' => $owner->id,
        'updated_by' => $owner->id, 'position' => 1,
    ]);

    $this->actingAs($owner);

    visit("/wiki/collections/{$collection->id}")
        ->assertDontSee('Remove from collection')
        ->click('[aria-label="Actions for Escalation process"]')
        ->assertSee('Edit')
        ->assertSee('Copy link')
        ->assertSee('Remove from collection')
        ->assertNoJavascriptErrors();
});

it('renders the page tree with drag handles and nesting', function () {
    [$owner, $collection] = wikiCollection('wiki-tree');

    $api = WikiPage::create([
        'tenant_id' => $collection->tenant_id, 'wiki_collection_id' => $collection->id,
        'title' => 'API documentation', 'created_by' => $owner->id,
        'updated_by' => $owner->id, 'position' => 1,
    ]);

    foreach (['Overview', 'Authentication'] as $i => $title) {
        WikiPage::create([
            'tenant_id' => $collection->tenant_id, 'wiki_collection_id' => $collection->id,
            'title' => $title, 'parent_id' => $api->id, 'created_by' => $owner->id,
            'updated_by' => $owner->id, 'position' => $i + 1,
        ]);
    }

    $this->actingAs($owner);

    // The nesting has to BE nested markup — SortableJS moves elements between <ul>s, so a
    // <table> could never have supported dropping one page onto another.
    visit("/wiki/collections/{$collection->id}")
        ->assertPresent('[data-page-list]')
        ->assertPresent('[data-drag]')
        ->assertPresent('[data-parent="'.$api->id.'"]')
        ->assertSee('Overview')
        ->assertSee('Authentication')
        ->assertNoJavascriptErrors();
});

it('lets you search when choosing what to nest a page under', function () {
    [$owner, $collection] = wikiCollection('wiki-combo');

    foreach (['API documentation', 'Escalation process', 'Severity levels'] as $i => $title) {
        WikiPage::create([
            'tenant_id' => $collection->tenant_id, 'wiki_collection_id' => $collection->id,
            'title' => $title, 'created_by' => $owner->id,
            'updated_by' => $owner->id, 'position' => $i + 1,
        ]);
    }

    $this->actingAs($owner);

    // A collection can hold a lot of pages; scrolling to find the parent is the part that
    // stops being workable first.
    visit("/wiki/collections/{$collection->id}")
        ->click('[aria-label="Actions for Severity levels"]')
        ->press('Edit')
        ->press('Top level')
        // A real ellipsis, not three dots — they render identically and match differently.
        ->assertPresent("input[placeholder='Search\u{2026}']")
        ->assertSee('API documentation')
        // Never itself: a page nested under itself vanishes from every tree that draws it.
        ->assertNoJavascriptErrors();
});

it('opens the action menu on the last row and on a nested one', function () {
    [$owner, $collection] = wikiCollection('wiki-lastrow');

    $api = WikiPage::create([
        'tenant_id' => $collection->tenant_id, 'wiki_collection_id' => $collection->id,
        'title' => 'API documentation', 'created_by' => $owner->id,
        'updated_by' => $owner->id, 'position' => 1,
    ]);
    WikiPage::create([
        'tenant_id' => $collection->tenant_id, 'wiki_collection_id' => $collection->id,
        'title' => 'Overview', 'parent_id' => $api->id, 'created_by' => $owner->id,
        'updated_by' => $owner->id, 'position' => 1,
    ]);
    WikiPage::create([
        'tenant_id' => $collection->tenant_id, 'wiki_collection_id' => $collection->id,
        'title' => 'Escalation process', 'created_by' => $owner->id,
        'updated_by' => $owner->id, 'position' => 2,
    ]);

    $this->actingAs($owner);

    // The card used to be `overflow-hidden`, to clip its header fill to the rounded corners.
    // It clipped the row menus too, so the last row's actions opened into nothing.
    // Two visits, not two clicks in one: an open menu lays a full-screen backdrop over the
    // page, so the second button is genuinely unclickable until the first closes.
    visit("/wiki/collections/{$collection->id}")
        ->click('[aria-label="Actions for Escalation process"]')
        ->assertSee('Remove from collection')
        ->assertNoJavascriptErrors();

    visit("/wiki/collections/{$collection->id}")
        ->click('[aria-label="Actions for Overview"]')
        ->assertSee('Copy link')
        ->assertNoJavascriptErrors();
});

it('collapses and expands a nested group', function () {
    [$owner, $collection] = wikiCollection('wiki-collapse');

    $api = WikiPage::create([
        'tenant_id' => $collection->tenant_id, 'wiki_collection_id' => $collection->id,
        'title' => 'API documentation', 'created_by' => $owner->id,
        'updated_by' => $owner->id, 'position' => 1,
    ]);

    foreach (['Overview', 'Authentication'] as $i => $title) {
        WikiPage::create([
            'tenant_id' => $collection->tenant_id, 'wiki_collection_id' => $collection->id,
            'title' => $title, 'parent_id' => $api->id, 'created_by' => $owner->id,
            'updated_by' => $owner->id, 'position' => $i + 1,
        ]);
    }

    $this->actingAs($owner);

    visit("/wiki/collections/{$collection->id}")
        ->assertSee('Overview')
        ->click('[aria-label="Collapse API documentation"]')
        ->assertDontSee('Overview')
        // The parent keeps saying how many it holds, so a collapsed group is not a hidden one.
        ->assertSee('API documentation')
        ->click('[aria-label="Expand API documentation"]')
        ->assertSee('Overview')
        ->assertNoJavascriptErrors();
});

it('offers no disclosure on a page with no children', function () {
    [$owner, $collection] = wikiCollection('wiki-leaf');

    WikiPage::create([
        'tenant_id' => $collection->tenant_id, 'wiki_collection_id' => $collection->id,
        'title' => 'Escalation process', 'created_by' => $owner->id,
        'updated_by' => $owner->id, 'position' => 1,
    ]);

    $this->actingAs($owner);

    // A control that discloses nothing is a control that has to be explained.
    visit("/wiki/collections/{$collection->id}")
        ->assertMissing('[aria-label="Collapse Escalation process"]')
        ->assertNoJavascriptErrors();
});

it('switches between List and Group views', function () {
    [$owner, $collection] = wikiCollection('wiki-views');

    $group = WikiCollectionGroup::create([
        'tenant_id' => $collection->tenant_id, 'wiki_collection_id' => $collection->id,
        'name' => 'Getting started', 'label' => 'Internal',
        'short_description' => 'Read these first.',
        'created_by' => $owner->id, 'position' => 1,
    ]);

    WikiPage::create([
        'tenant_id' => $collection->tenant_id, 'wiki_collection_id' => $collection->id,
        'title' => 'Escalation process', 'wiki_group_id' => $group->id,
        'created_by' => $owner->id, 'updated_by' => $owner->id, 'position' => 1,
    ]);
    WikiPage::create([
        'tenant_id' => $collection->tenant_id, 'wiki_collection_id' => $collection->id,
        'title' => 'Severity levels', 'created_by' => $owner->id,
        'updated_by' => $owner->id, 'position' => 2,
    ]);

    $this->actingAs($owner);

    // Two ways of looking at the same pages: the columns in one, the sections in the other.
    visit("/wiki/collections/{$collection->id}")
        ->assertSee('Last activity')
        ->press('Group')
        ->assertSee('Getting started')
        ->assertSee('Internal')
        ->assertSee('Read these first.')
        // A page filed nowhere must not vanish because the view changed.
        ->assertSee('Ungrouped')
        ->assertSee('Severity levels')
        ->assertDontSee('Last activity')
        // Sections and their pages are both draggable, and the group picker is the app's
        // searchable combo rather than a native select.
        ->assertPresent('[data-group-list]')
        ->assertPresent('[data-group-drag]')
        ->assertPresent('[data-group-pages]')
        ->assertMissing('select')
        ->assertNoJavascriptErrors();
});

it('collapses a group without losing it as a drop target', function () {
    [$owner, $collection] = wikiCollection('wiki-groupcollapse');

    $group = WikiCollectionGroup::create([
        'tenant_id' => $collection->tenant_id, 'wiki_collection_id' => $collection->id,
        'name' => 'Getting started', 'created_by' => $owner->id, 'position' => 1,
    ]);
    WikiPage::create([
        'tenant_id' => $collection->tenant_id, 'wiki_collection_id' => $collection->id,
        'title' => 'Escalation process', 'wiki_group_id' => $group->id,
        'created_by' => $owner->id, 'updated_by' => $owner->id, 'position' => 1,
    ]);

    $this->actingAs($owner);

    visit("/wiki/collections/{$collection->id}")
        ->press('Group')
        ->assertSee('Escalation process')
        ->click('[aria-label="Collapse Getting started"]')
        ->assertDontSee('Escalation process')
        // The list is hidden, not removed — a collapsed section is still somewhere you can
        // file a page you are not currently reading.
        ->assertPresent('[data-group-pages]')
        ->click('[aria-label="Expand Getting started"]')
        ->assertSee('Escalation process')
        ->assertNoJavascriptErrors();
});

it('offers the group form with all four fields', function () {
    [$owner, $collection] = wikiCollection('wiki-groupform');
    $this->actingAs($owner);

    visit("/wiki/collections/{$collection->id}")
        ->press('Group')
        ->assertSee('No groups yet')
        ->press('Create a group')
        ->assertSee('Group name')
        ->assertSee('Label')
        ->assertSee('Short description')
        ->assertSee('Long description')
        ->assertNoJavascriptErrors();
});
