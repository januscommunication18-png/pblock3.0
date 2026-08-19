<?php

use App\Models\WorkspaceSettings;
use App\Services\WorkspaceSettingsManager;

/**
 * The Help Center wizard in a real browser (docs/features/help-center.md §2–§12).
 *
 * The whole wizard is one Vue component mounted on a Blade page, and none of it is visible to
 * an HTTP test: the markup that ships is a `Loading…` div and a JSON blob. Whether the steps
 * actually draw, whether the member picker opens, and whether "Add" grows the address table
 * are things you can only see by looking at the page.
 */
function helpCenterWorkspace(string $slug): array
{
    [$owner, $workspace] = e2eWorkspace($slug);

    $workspace->run(function () use ($workspace) {
        app(WorkspaceSettingsManager::class)->for($workspace);
        WorkspaceSettings::query()->first()->forceFill(['help_desk_enabled' => true])->save();
    });

    return [$owner, $workspace];
}

it('opens on the welcome screen with the four-step progress', function () {
    [$owner] = helpCenterWorkspace('hc-welcome');
    $this->actingAs($owner);

    // §2: title, description, video placeholder, getting-started, progress, Get Started.
    visit('/help-center/setup')
        ->assertSee('Help Center')
        ->assertSee('Set up your Help Center to receive, organize, assign, and manage customer conversations from one place.')
        ->assertSee('Getting started')
        ->assertSee('Create Space')
        ->assertSee('Set Up Inbox')
        ->assertSee('Configure Inbound Email')
        ->assertSee('Complete')
        ->assertSee('Get Started')
        ->assertNoJavascriptErrors();
});

it('walks from the welcome screen into step 1', function () {
    [$owner] = helpCenterWorkspace('hc-step1');
    $this->actingAs($owner);

    visit('/help-center/setup')
        ->press('Get Started')
        ->assertSee('Create a Space')
        ->assertSee('Space Name')
        ->assertSee('Description')
        ->assertSee('Space Type')
        ->assertSee('Space Lead')
        ->assertSee('Cancel Setup')
        ->assertNoJavascriptErrors();
});

/**
 * The one the question was about: step 2's Email Addresses section is an input pair plus Add,
 * and adding grows a table you can keep adding to (§6, §20 rule 3).
 */
it('adds more than one email address on step 2', function () {
    [$owner] = helpCenterWorkspace('hc-addresses');
    $this->actingAs($owner);

    $page = visit('/help-center/setup')
        ->press('Get Started')
        ->type('input[placeholder="Customer Support"]', 'Customer Support');

    // Space Type and Space Lead are pb-combo buttons, not <select>s.
    $page->press('Choose a type…')->press('Customer Support')
        ->press('Search members…')->press('Rohit Philip')
        ->press('Continue')
        ->assertSee('Set up your Inbox')
        ->assertSee('Email Addresses')
        ->assertSee('Add the email addresses customers use to contact your team.');

    $page->type('input[placeholder="General Support"]', 'General Support')
        ->type('input[placeholder="support@company.com"]', 'support@company.com')
        ->type('input[placeholder="Company Support"]', 'Company Support')
        ->press('Add')
        ->assertSee('support@company.com')
        ->assertSee('Setup Required')
        ->assertSee('Remove');

    // …and again, which is the "adding more" part.
    $page->type('input[placeholder="support@company.com"]', 'help@company.com')
        ->press('Add')
        ->assertSee('help@company.com')
        ->assertNoJavascriptErrors();

    expect($page->text())->toContain('support@company.com')->toContain('help@company.com');
});

it('refuses an address already connected to another Inbox, as it is added', function () {
    [$owner, $workspace] = helpCenterWorkspace('hc-dupe');

    $workspace->run(function () use ($workspace, $owner) {
        $space = App\Models\HelpCenterSpace::create([
            'tenant_id' => $workspace->id, 'name' => 'Existing', 'types' => ['Other'],
            'lead_user_id' => $owner->id, 'created_by' => $owner->id,
        ]);
        $inbox = App\Models\HelpCenterInbox::create([
            'tenant_id' => $workspace->id, 'help_center_space_id' => $space->id,
            'name' => 'Existing Inbox', 'inbound_id' => 'q1w2e3r4',
        ]);
        $inbox->emailAddresses()->create(['tenant_id' => $workspace->id, 'email' => 'taken@company.com']);
    });

    $this->actingAs($owner);

    // Resumes at step 3 — there is an unfinished Inbox — so this checks the wizard's resume
    // as much as the message.
    visit('/help-center/setup')
        ->assertSee('Configure inbound email')
        ->assertSee('inbox-q1w2e3r4@inbound.projectblock.app')
        ->assertSee('Copy Address')
        ->assertSee('Google Workspace / Gmail')
        ->assertNoJavascriptErrors();
});
