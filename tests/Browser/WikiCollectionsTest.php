<?php

use App\Models\WikiCollection;
use App\Models\WikiCollectionGroup;
use App\Models\WikiCollectionMember;
use App\Models\WikiCover;
use App\Models\WikiPage;
use App\Models\WorkspaceSettings;
use App\Services\WorkspaceSettingsManager;

/**
 * Wiki collections in a real browser (docs/features/wiki.md).
 *
 * The modal is Vue mounted on the Wiki screen, opened by buttons the SHARED SIDEBAR renders
 * outside that root and binds by id. None of that wiring is visible to an HTTP test — the
 * markup ships either way — and neither is a `<details>` that fails to disclose.
 */
function wikiWorkspace(string $slug): array
{
    [$owner, $workspace] = e2eWorkspace($slug);

    $workspace->run(function () use ($workspace) {
        app(WorkspaceSettingsManager::class)->for($workspace);
        WorkspaceSettings::query()->first()->forceFill(['wiki_enabled' => true])->save();
    });

    return [$owner, $workspace];
}

it('opens the collection modal from the sidebar', function () {
    [$owner] = wikiWorkspace('wiki-modal');
    $this->actingAs($owner);

    visit('/wiki')
        ->press('Create your first collection')
        ->assertSee('New collection')
        ->assertSee('Who can see it')
        ->assertSee('Only people invited to the collection can open it.')
        ->assertNoJavascriptErrors();
});

it('opens the same modal from the Home empty state', function () {
    [$owner] = wikiWorkspace('wiki-empty');
    $this->actingAs($owner);

    // One modal, reachable from every place that offers to create a collection.
    visit('/wiki')
        ->press('Create a collection')
        ->assertSee('New collection')
        ->assertNoJavascriptErrors();
});

it('lists collections in an expandable group, like Projects', function () {
    [$owner, $workspace] = wikiWorkspace('wiki-group');

    $workspace->run(function () use ($workspace, $owner) {
        foreach ([['Engineering', 'public'], ['Leadership', 'private']] as $i => [$name, $visibility]) {
            WikiCollection::create([
                'tenant_id' => $workspace->id, 'name' => $name, 'visibility' => $visibility,
                'created_by' => $owner->id, 'position' => $i + 1,
            ]);
        }
    });

    $this->actingAs($owner);

    // A <details> disclosure with a "+" beside its heading — the same shape the Projects group
    // uses, so the two halves of the app do not navigate differently for no reason.
    visit('/wiki')
        ->assertSee('Engineering')
        ->assertSee('Leadership')
        ->assertDontSee('Create your first collection')
        ->assertNoJavascriptErrors();
});

it('opens a collection from the sidebar and shows its details', function () {
    [$owner, $workspace, $project] = e2eWorkspace('wiki-detail');
    $mate = e2eTeammate($workspace, $project, 'Sarah Johnson', 'sarah-'.uniqid().'@example.com');

    $workspace->run(function () use ($workspace, $owner, $mate) {
        app(WorkspaceSettingsManager::class)->for($workspace);
        WorkspaceSettings::query()->first()->forceFill(['wiki_enabled' => true])->save();

        $c = WikiCollection::create([
            'tenant_id' => $workspace->id, 'name' => 'Help Desk Software',
            'description' => 'Troubleshooting guides and escalation processes.',
            'visibility' => 'private', 'created_by' => $owner->id, 'position' => 1,
        ]);
        WikiCollectionMember::create([
            'tenant_id' => $workspace->id, 'wiki_collection_id' => $c->id,
            'user_id' => $mate->id, 'permission' => 'edit', 'invited_by' => $owner->id,
        ]);
    });

    $this->actingAs($owner);

    visit('/wiki')
        ->click('Help Desk Software')
        ->assertSee('Help Desk Software')
        ->assertSee('Private')
        ->assertSee('Troubleshooting guides and escalation processes.')
        // Who can open it, then what to do with it, then what is in it.
        ->assertSee('Access')
        ->assertSee('Add page')
        ->assertSee('Link a page')
        ->assertSee('No pages yet')
        ->assertNoJavascriptErrors();
});

it('explains access differently on a public collection', function () {
    [$owner, $workspace] = wikiWorkspace('wiki-public');

    $workspace->run(fn () => WikiCollection::create([
        'tenant_id' => $workspace->id, 'name' => 'Company Handbook',
        'visibility' => 'public', 'created_by' => $owner->id, 'position' => 1,
    ]));

    $this->actingAs($owner);

    /*
     * The box used to be hidden here: everyone in the workspace could already read a public
     * collection, so a row of avatars answered a question nobody asked. Once a collection can
     * carry members from OUTSIDE the workspace, visibility stops being the whole answer — so
     * the box shows, and says which part of the answer it is giving.
     */
    visit('/wiki')
        ->click('Company Handbook')
        ->assertSee('Public')
        ->assertSee('Access')
        ->assertSee('Everyone in the workspace can read this')
        ->assertDontSee('Only these people can open this collection')
        ->assertNoJavascriptErrors();
});

it('offers both kinds of invitation in the Access box', function () {
    // e2eWorkspace + a teammate, not wikiWorkspace: with nobody else in the workspace the invite
    // modal correctly says "Everyone in this workspace already has access" and never draws the
    // picker this test is about.
    [$owner, $workspace, $project] = e2eWorkspace('wiki-access-invites');
    e2eTeammate($workspace, $project, 'Sarah Johnson', 'sarah-'.uniqid().'@example.com');

    $collection = $workspace->run(function () use ($workspace, $owner) {
        app(WorkspaceSettingsManager::class)->for($workspace);
        WorkspaceSettings::query()->first()->forceFill(['wiki_enabled' => true])->save();

        return WikiCollection::create([
            'tenant_id' => $workspace->id, 'name' => 'Help Desk Software',
            'visibility' => 'private', 'created_by' => $owner->id, 'position' => 1,
        ]);
    });

    $this->actingAs($owner);

    // Two ways in, each saying which. The dashed "+" that used to sit at the end of the faces
    // could only have meant one of them, and a control that silently picks is worse than two
    // that say so.
    visit("/wiki/collections/{$collection->id}")
        ->assertSee('Team members')
        ->assertSee('Invite team member')
        // Rendered plainly unavailable until Slice 2 rather than as a control that goes nowhere.
        ->assertSee('Invite external member')
        ->click('Invite team member')
        ->assertSee('Add people')
        ->assertSee('What they can do')
        ->assertNoJavascriptErrors();
});

it('names each face on hover', function () {
    [$owner, $workspace, $project] = e2eWorkspace('wiki-tip');
    $mate = e2eTeammate($workspace, $project, 'Sarah Johnson', 'sarah-'.uniqid().'@example.com');

    $collection = $workspace->run(function () use ($workspace, $owner, $mate) {
        app(WorkspaceSettingsManager::class)->for($workspace);
        WorkspaceSettings::query()->first()->forceFill(['wiki_enabled' => true])->save();

        $c = WikiCollection::create([
            'tenant_id' => $workspace->id, 'name' => 'Help Desk Software',
            'visibility' => 'private', 'created_by' => $owner->id, 'position' => 1,
        ]);
        WikiCollectionMember::create([
            'tenant_id' => $workspace->id, 'wiki_collection_id' => $c->id,
            'user_id' => $mate->id, 'permission' => 'edit', 'invited_by' => $owner->id,
        ]);

        return $c;
    });

    $this->actingAs($owner);

    // A face shows an initial, so its meaning is precisely what is NOT written on it. The
    // tooltip is the app's own (`data-tip`), whose styles used to live only in the work-item
    // stylesheet — it rendered as bare text at the foot of every other screen.
    visit('/wiki/collections/'.$collection->id)
        ->assertDontSee('Sarah Johnson')
        ->hover('[data-tip="Sarah Johnson — Can edit"]')
        ->assertSee('Sarah Johnson — Can edit')
        ->assertNoJavascriptErrors();
});

it('edits the collection from the pencil beside its name', function () {
    [$owner, $workspace] = wikiWorkspace('wiki-editcol');

    $collection = $workspace->run(fn () => WikiCollection::create([
        'tenant_id' => $workspace->id, 'name' => 'Help desk softrware',
        'description' => 'Troubleshooting guides and escalation processes.',
        'visibility' => 'public', 'created_by' => $owner->id, 'position' => 1,
    ]));

    $this->actingAs($owner);

    // Pre-filled, so a typo in the name is a correction rather than a retype.
    visit("/wiki/collections/{$collection->id}")
        ->click('[aria-label="Edit collection"]')
        ->assertSee('Edit collection')
        ->assertValue('input.pb-input', 'Help desk softrware')
        ->assertSee('Who can see it')
        ->assertNoJavascriptErrors();
});

it('offers the public URL card below the pages, and refuses a private collection', function () {
    [$owner, $workspace] = wikiWorkspace('wiki-publish');

    $collection = $workspace->run(fn () => WikiCollection::create([
        'tenant_id' => $workspace->id, 'name' => 'Help Desk Software',
        'visibility' => 'private', 'created_by' => $owner->id, 'position' => 1,
    ]));

    $this->actingAs($owner);

    visit("/wiki/collections/{$collection->id}")
        ->assertSee('Draft')
        ->assertSee('Public URL')
        ->assertSee('cannot be changed afterwards')
        // Publishing puts it on the open internet; "private" says who may read it. The
        // contradiction is refused, and said before the click rather than after.
        ->assertSee('A private collection cannot be published')
        ->assertNoJavascriptErrors();
});

it('reads the collection as a document, with nav on the left', function () {
    [$owner, $workspace] = wikiWorkspace('wiki-reader');

    $collection = $workspace->run(function () use ($workspace, $owner) {
        $c = WikiCollection::create([
            'tenant_id' => $workspace->id, 'name' => 'Help Desk Software',
            'description' => 'Troubleshooting guides and escalation processes.',
            'visibility' => 'public', 'created_by' => $owner->id, 'position' => 1,
        ]);

        $g = WikiCollectionGroup::create([
            'tenant_id' => $workspace->id, 'wiki_collection_id' => $c->id,
            'name' => 'Getting started', 'created_by' => $owner->id, 'position' => 1,
        ]);

        WikiPage::create(['tenant_id' => $workspace->id, 'wiki_collection_id' => $c->id,
            'title' => 'Escalation process', 'wiki_group_id' => $g->id,
            'content' => '<p>Ring the on-call engineer.</p>',
            'created_by' => $owner->id, 'updated_by' => $owner->id, 'position' => 1]);
        WikiPage::create(['tenant_id' => $workspace->id, 'wiki_collection_id' => $c->id,
            'title' => 'Glossary', 'created_by' => $owner->id,
            'updated_by' => $owner->id, 'position' => 2]);

        return $c;
    });

    $this->actingAs($owner);

    // Header across the top, groups and pages down the left, the document on the right.
    visit("/wiki/collections/{$collection->id}/preview")
        ->assertSee('Preview')
        ->assertSee('Getting started')
        ->assertSee('Escalation process')
        ->assertSee('Ring the on-call engineer')
        // The filter searches what is already on the page — no round trip to hide four links.
        ->assertPresent('#wiki-nav-filter')
        ->assertNoJavascriptErrors();
});

it('lists the page headings under On this page', function () {
    [$owner, $workspace] = wikiWorkspace('wiki-toc');

    $collection = $workspace->run(function () use ($workspace, $owner) {
        $c = WikiCollection::create([
            'tenant_id' => $workspace->id, 'name' => 'Help Desk Software',
            'visibility' => 'public', 'created_by' => $owner->id, 'position' => 1,
        ]);

        WikiPage::create([
            'tenant_id' => $workspace->id, 'wiki_collection_id' => $c->id,
            'title' => 'Escalation process',
            'content' => '<h1>Before you escalate</h1><p>Check.</p><h2>Timings</h2><p>Thirty minutes.</p>',
            'created_by' => $owner->id, 'updated_by' => $owner->id, 'position' => 1,
        ]);

        return $c;
    });

    $this->actingAs($owner);

    // The anchors are written server-side, so they work on the first paint rather than once
    // some script has run.
    visit("/wiki/collections/{$collection->id}/preview")
        ->assertSee('On this page')
        ->assertSee('Before you escalate')
        ->assertSee('Timings')
        ->assertPresent('#before-you-escalate')
        ->assertPresent('a[href="#timings"]')
        ->assertNoJavascriptErrors();
});

it('adds a page underneath another from the row plus', function () {
    [$owner, $workspace] = wikiWorkspace('wiki-subpage');

    $collection = $workspace->run(function () use ($workspace, $owner) {
        $c = WikiCollection::create([
            'tenant_id' => $workspace->id, 'name' => 'Help Desk Software',
            'visibility' => 'public', 'created_by' => $owner->id, 'position' => 1,
        ]);

        WikiPage::create(['tenant_id' => $workspace->id, 'wiki_collection_id' => $c->id,
            'title' => 'API documentation', 'created_by' => $owner->id,
            'updated_by' => $owner->id, 'position' => 1]);

        return $c;
    });

    $this->actingAs($owner);

    // Nesting decided while naming the page. The alternative — create it loose, then drag it
    // under its parent — is two steps for something the user already knew when they clicked.
    visit("/wiki/collections/{$collection->id}")
        ->click('[aria-label="Add a page near API documentation"]')
        ->assertSee('Add sub-page')
        ->click('Add sub-page')
        ->assertSee('This page will sit underneath')
        ->assertSee('API documentation')
        ->assertNoJavascriptErrors();
});

it('adds a page from inside a group in Group view', function () {
    [$owner, $workspace] = wikiWorkspace('wiki-groupadd');

    $collection = $workspace->run(function () use ($workspace, $owner) {
        $c = WikiCollection::create([
            'tenant_id' => $workspace->id, 'name' => 'Help Desk Software',
            'visibility' => 'public', 'created_by' => $owner->id, 'position' => 1,
        ]);

        WikiCollectionGroup::create([
            'tenant_id' => $workspace->id, 'wiki_collection_id' => $c->id,
            'name' => 'Getting started', 'created_by' => $owner->id, 'position' => 1,
        ]);

        return $c;
    });

    $this->actingAs($owner);

    // The section says which group it will land in before the name is typed, so filing is a
    // decision rather than something to check afterwards.
    visit("/wiki/collections/{$collection->id}")
        ->press('Group')
        ->click('[aria-label="Add a page to Getting started"]')
        ->assertSee('This page will be filed under')
        ->assertSee('Getting started')
        ->assertNoJavascriptErrors();
});

it('offers the same action from an empty group', function () {
    [$owner, $workspace] = wikiWorkspace('wiki-groupempty');

    $collection = $workspace->run(function () use ($workspace, $owner) {
        $c = WikiCollection::create([
            'tenant_id' => $workspace->id, 'name' => 'Help Desk Software',
            'visibility' => 'public', 'created_by' => $owner->id, 'position' => 1,
        ]);

        WikiCollectionGroup::create([
            'tenant_id' => $workspace->id, 'wiki_collection_id' => $c->id,
            'name' => 'Escalation', 'created_by' => $owner->id, 'position' => 1,
        ]);

        return $c;
    });

    $this->actingAs($owner);

    // "Drag one here" is no help when there is nothing anywhere to drag.
    visit("/wiki/collections/{$collection->id}")
        ->press('Group')
        ->assertSee('No pages in this group yet')
        ->click('add one')
        ->assertSee('This page will be filed under')
        ->assertNoJavascriptErrors();
});

it('adds a sub-group from the section it belongs to', function () {
    [$owner, $workspace] = wikiWorkspace('wiki-subgroup');

    $collection = $workspace->run(function () use ($workspace, $owner) {
        $c = WikiCollection::create([
            'tenant_id' => $workspace->id, 'name' => 'Help Desk Software',
            'visibility' => 'public', 'created_by' => $owner->id, 'position' => 1,
        ]);

        $g = WikiCollectionGroup::create([
            'tenant_id' => $workspace->id, 'wiki_collection_id' => $c->id,
            'name' => 'Getting started', 'created_by' => $owner->id, 'position' => 1,
        ]);
        WikiCollectionGroup::create([
            'tenant_id' => $workspace->id, 'wiki_collection_id' => $c->id,
            'parent_id' => $g->id, 'name' => 'Installation',
            'created_by' => $owner->id, 'position' => 1,
        ]);

        return $c;
    });

    $this->actingAs($owner);

    // One "+" per section offering both things a section can hold. Two separate buttons would
    // put "Add sub-group" on every heading of a collection that never uses them.
    visit("/wiki/collections/{$collection->id}")
        ->press('Group')
        ->assertSee('Installation')
        ->click('[aria-label="Add to Getting started"]')
        ->assertSee('Add sub-group')
        ->click('Add sub-group')
        ->assertSee('New sub-group')
        ->assertSee('This section will sit inside')
        ->assertNoJavascriptErrors();
});

it('opens the New page modal from the sidebar', function () {
    [$owner, $workspace] = wikiWorkspace('wiki-newpage');

    $workspace->run(fn () => WikiCollection::create([
        'tenant_id' => $workspace->id, 'name' => 'Company Handbook',
        'visibility' => 'public', 'created_by' => $owner->id, 'position' => 1,
    ]));

    $this->actingAs($owner);

    // The modal is where the collection is chosen, which is what lets New page be a global
    // action at all — a page needs one and the sidebar does not know which.
    visit('/wiki')
        ->click('New page')
        ->assertSee('Page name')
        ->assertSee('Collection')
        ->assertSee('You can write the content once the page opens')
        ->assertNoJavascriptErrors();
});

it('asks before archiving, and names what archiving costs', function () {
    [$owner, $workspace] = wikiWorkspace('wiki-archive-confirm');

    $collection = $workspace->run(fn () => WikiCollection::create([
        'tenant_id' => $workspace->id, 'name' => 'Help Desk Software',
        'visibility' => 'private', 'created_by' => $owner->id, 'position' => 1,
    ]));

    $this->actingAs($owner);

    // Archiving closes the collection to everybody invited to it (WIKI-D5), which is far more
    // than the word suggests — so the dialog says so rather than asking "are you sure?".
    visit("/wiki/collections/{$collection->id}")
        ->click('[aria-label="Collection actions"]')
        ->click('Archive collection')
        ->assertSee('Archive this collection?')
        ->assertSee('lose access')
        ->assertSee('Its pages, groups and settings are all kept')
        ->assertNoJavascriptErrors();

    // Cancelling leaves it exactly where it was.
    expect($workspace->run(fn () => WikiCollection::find($collection->id)->archived_at))->toBeNull();
});

it('asks before deleting, and says who loses access', function () {
    [$owner, $workspace] = wikiWorkspace('wiki-delete-confirm');

    $collection = $workspace->run(function () use ($workspace, $owner) {
        $c = WikiCollection::create([
            'tenant_id' => $workspace->id, 'name' => 'Help Desk Software',
            'visibility' => 'private', 'created_by' => $owner->id, 'position' => 1,
        ]);

        WikiPage::create([
            'tenant_id' => $workspace->id, 'wiki_collection_id' => $c->id,
            'title' => 'Escalation process',
            'created_by' => $owner->id, 'updated_by' => $owner->id, 'position' => 1,
        ]);

        return $c;
    });

    $this->actingAs($owner);

    visit("/wiki/collections/{$collection->id}")
        ->click('[aria-label="Collection actions"]')
        ->click('Delete collection')
        ->assertSee('Delete this collection?')
        ->assertSee('users will lose access to it')
        ->assertSee('Any external members with access to this collection will also lose access')
        // Our deletion rule is that the pages go with it, so the dialog counts them.
        ->assertSee('page')
        ->assertSee('This cannot be undone')
        // A way out, offered where the decision is made.
        ->assertSee('archive it instead')
        ->assertNoJavascriptErrors();

    // Nothing happens until the name is typed and Delete Collection is pressed.
    expect($workspace->run(fn () => WikiCollection::find($collection->id)))->not->toBeNull();
});

it('searches from the header, not from the navigation', function () {
    [$owner, $workspace] = wikiWorkspace('wiki-header-search');

    $collection = $workspace->run(function () use ($workspace, $owner) {
        $c = WikiCollection::create([
            'tenant_id' => $workspace->id, 'name' => 'Help Desk Software',
            'visibility' => 'public', 'created_by' => $owner->id, 'position' => 1,
        ]);

        foreach (['Escalation process', 'Handover notes'] as $i => $title) {
            WikiPage::create([
                'tenant_id' => $workspace->id, 'wiki_collection_id' => $c->id,
                'title' => $title, 'content' => '<p>Body.</p>',
                'created_by' => $owner->id, 'updated_by' => $owner->id, 'position' => $i + 1,
            ]);
        }

        WikiCover::forCollection($c)->fill([
            'is_enabled' => true, 'title' => 'Product Knowledge Base',
        ])->save();

        return $c;
    });

    $this->actingAs($owner);

    // Results are a list you choose from, not a filter of the column beside it.
    visit("/wiki/collections/{$collection->id}/preview")
        ->assertDontSee('Filter pages')
        ->type('#wiki-search', 'handover')
        ->assertSee('Handover notes')
        ->assertNoJavascriptErrors();
});
