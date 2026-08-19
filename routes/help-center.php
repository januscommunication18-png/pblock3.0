<?php

use App\Http\Controllers\HelpCenter\EmailAddressController;
use App\Http\Controllers\HelpCenter\HelpCenterController;
use App\Http\Controllers\HelpCenter\InboundTestController;
use App\Http\Controllers\HelpCenter\InboxController;
use App\Http\Controllers\HelpCenter\SetupController;
use App\Http\Controllers\HelpCenter\SpaceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Help Center (docs/features/help-center.md)
|--------------------------------------------------------------------------
| Behind 'workspace.tenancy' like the other workspace-level areas, and behind the workspace's
| own Help Center switch — enforced per action by GuardsHelpCenter rather than by a route
| middleware, because the check needs the resolved workspace and answers 404, not 403: a
| workspace that never enabled the app has no Help Center to be forbidden from.
|
| require __DIR__.'/help-center.php'; from web.php
|
| The previous module's routes are archived with it in legacy/help-desk/routes/help-desk.php and
| share nothing with these (HC-D10).
*/

Route::middleware(['auth', 'workspace.tenancy'])
    ->prefix('help-center')
    ->name('help-center.')
    ->group(function () {

        /*
         * The front door. Overview when setup is done, the wizard when it is not (§1) —
         * the redirect lives in the controller because the answer is a database question.
         */
        Route::get('/', [HelpCenterController::class, 'index'])->name('index');
        Route::get('/conversations', [HelpCenterController::class, 'conversations'])->name('conversations');
        Route::get('/inboxes', [HelpCenterController::class, 'inboxes'])->name('inboxes');

        /*
         * First-run onboarding (§2, §21). One screen, four steps, and the step is derived
         * rather than routed: /setup/2 would be a URL that could disagree with the data.
         */
        Route::get('/setup', [SetupController::class, 'show'])->name('setup');

        /*
         * One endpoint per step. Each validates and merges into the draft; NONE of them creates
         * anything (HC-D11) — `store` is the only one that does.
         *
         * The step is not in the URL. It lives on the draft, because a step in the address bar
         * is a second record of where somebody is, free to disagree with what they have filled
         * in — and /setup/4 would be a link straight past the validation of steps 1 to 3.
         */
        Route::post('/setup/space', [SetupController::class, 'space'])->name('setup.space');
        Route::post('/setup/team', [SetupController::class, 'team'])->name('setup.team');
        Route::post('/setup/inbox', [SetupController::class, 'inbox'])->name('setup.inbox');
        Route::post('/setup/workflow', [SetupController::class, 'workflow'])->name('setup.workflow');
        Route::post('/setup/settings', [SetupController::class, 'settings'])->name('setup.settings');

        // Back, and Review's per-section Edit (P2 §27) — a write, because moving backward has
        // to preserve what is on screen.
        Route::post('/setup/back', [SetupController::class, 'back'])->name('setup.back');

        Route::post('/setup/check-address', [SetupController::class, 'checkAddress'])->name('setup.check-address');

        // Step 6's Create Help Desk (P2 §28), and Cancel Setup.
        Route::post('/setup/store', [SetupController::class, 'store'])->name('setup.store');
        Route::post('/setup/cancel', [SetupController::class, 'cancel'])->name('setup.cancel');

        /*
         * Spaces — the module's management screen (P3 §20), replacing "Inboxes" in the nav.
         *
         * BEFORE every /spaces/{space} route, or the literal segment would be swallowed as an
         * id. `whereNumber` on those makes that impossible anyway; the order is kept because it
         * is the rule, not because this particular pair would break.
         */
        Route::get('/spaces', [SpaceController::class, 'index'])->name('spaces.index');
        Route::post('/spaces', [SpaceController::class, 'store'])->name('spaces.store');

        /*
         * BEFORE the {view} route below, or `inboxes` would be read as a system view — the same
         * ordering routes/wiki.php needs for its `reorder` paths. The verbs differ here, so
         * nothing would actually break; it is written this way so it stays true if a GET is
         * ever added.
         */
        Route::post('/spaces/{space}/inboxes', [InboxController::class, 'store'])
            ->whereNumber('space')->name('inboxes.store');

        Route::get('/spaces/{space}', [SpaceController::class, 'show'])
            ->whereNumber('space')->name('spaces.show');

        // The Space Overview's inbound test (P7): start one, and poll the latest.
        Route::post('/spaces/{space}/inbound-test', [InboundTestController::class, 'store'])
            ->whereNumber('space')->name('spaces.inbound-test.store');
        Route::get('/spaces/{space}/inbound-test', [InboundTestController::class, 'show'])
            ->whereNumber('space')->name('spaces.inbound-test.show');

        // Edit an existing Space — an UPDATE against this id only (P3 §5, §26).
        Route::patch('/spaces/{space}', [SpaceController::class, 'update'])
            ->whereNumber('space')->name('spaces.update');

        // Archive is reversible and touches only this Space; delete takes everything under it.
        Route::patch('/spaces/{space}/archive', [SpaceController::class, 'archive'])
            ->whereNumber('space')->name('spaces.archive');
        Route::delete('/spaces/{space}', [SpaceController::class, 'destroy'])
            ->whereNumber('space')->name('spaces.destroy');

        /*
         * A Space's six sections (P4): Overview, Conversations, Inbox, Workflow, Members,
         * Settings. ENUMERATED from config, so an unknown segment 404s rather than rendering an
         * empty screen, and so the config stays the single source of what a Space contains.
         *
         * This replaced /spaces/{space}/{view}, which routed the six CONVERSATION views. Those
         * are filters on the Conversations section now, not URLs of their own.
         */
        Route::get('/spaces/{space}/{section}', [SpaceController::class, 'section'])
            ->whereNumber('space')
            ->whereIn('section', array_keys((array) config('help-center.space_sections')))
            ->name('spaces.section');

        // Email addresses on an Inbox (§6, §7).
        Route::post('/inboxes/{inbox}/addresses', [EmailAddressController::class, 'store'])
            ->whereNumber('inbox')->name('addresses.store');
        Route::delete('/inboxes/{inbox}/addresses/{address}', [EmailAddressController::class, 'destroy'])
            ->whereNumber(['inbox', 'address'])->name('addresses.destroy');
    });
