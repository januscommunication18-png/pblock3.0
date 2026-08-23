<?php

use App\Http\Controllers\HelpCenter\CompanyController;
use App\Http\Controllers\HelpCenter\CompanyCustomerController;
use App\Http\Controllers\HelpCenter\CustomerController;
use App\Http\Controllers\HelpCenter\CustomFieldController;
use App\Http\Controllers\HelpCenter\MetadataMappingController;
use App\Http\Controllers\HelpCenter\EmailAddressController;
use App\Http\Controllers\HelpCenter\HelpCenterController;
use App\Http\Controllers\HelpCenter\InboundTestController;
use App\Http\Controllers\HelpCenter\InboxController;
use App\Http\Controllers\HelpCenter\InboxQueueController;
use App\Http\Controllers\HelpCenter\PublicRatingController;
use App\Http\Controllers\HelpCenter\RatingController;
use App\Http\Controllers\HelpCenter\RequestAttachmentController;
use App\Http\Controllers\HelpCenter\RequestController;
use App\Http\Controllers\HelpCenter\EmailTemplateController;
use App\Http\Controllers\HelpCenter\RequestNoteController;
use App\Http\Controllers\HelpCenter\RequestSnoozeController;
use App\Http\Controllers\HelpCenter\SetupController;
use App\Http\Controllers\HelpCenter\SpaceController;
use App\Http\Controllers\HelpCenter\SpaceEntryController;
use App\Http\Controllers\HelpCenter\SpaceMemberController;
use App\Http\Controllers\HelpCenter\SpaceSettingsController;
use App\Http\Controllers\HelpCenter\TagController;
use App\Models\HelpCenterSpace;
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

/*
 * The CUSTOMER'S rating page (P56 §9) — no auth, no tenancy, no workspace.
 *
 * The only public screen this module has. Its visitor is a customer of a customer: not a user of
 * this application, with no account and no session, arriving from a link in an email. The 48-
 * character token IS the authorisation — see PublicRatingController for what that costs and why
 * the page shows nothing about the ticket.
 *
 * Outside the group below AND outside `auth`, deliberately: putting it behind either would
 * redirect a customer to a sign-in page for an application they have never heard of.
 *
 * Throttled, because it is the one endpoint in this module a stranger can reach: 30 a minute is
 * far above any human rating experience and far below a useful brute-force against a 48-character
 * token — which is itself the real defence.
 */
Route::middleware('throttle:30,1')->group(function () {
    Route::get('/rating/{token}', [PublicRatingController::class, 'show'])->name('help-center.rating.show');
    Route::post('/rating/{token}', [PublicRatingController::class, 'store'])->name('help-center.rating.store');
});

/*
 * The one Help Center URL that is NOT behind 'workspace.tenancy' (P10).
 *
 * It is the address emails link to, and an emailed link is opened by somebody whose active
 * workspace is whatever they last used — so it has to establish the workspace rather than
 * assume it. See SpaceEntryController for why that cannot be done inside the group below.
 */
Route::middleware('auth')
    ->get('/help-center/go/spaces/{space}', SpaceEntryController::class)
    ->whereNumber('space')
    ->name('help-center.spaces.open');

Route::middleware(['auth', 'workspace.tenancy'])
    ->prefix('help-center')
    ->name('help-center.')
    ->group(function () {

        /*
         * The front door. Overview when setup is done, the wizard when it is not (§1) —
         * the redirect lives in the controller because the answer is a database question.
         */
        Route::get('/', [HelpCenterController::class, 'index'])->name('index');
        Route::get('/inboxes', [HelpCenterController::class, 'inboxes'])->name('inboxes');

        /*
         * Company & Customer (P75 §11–§13) — the management area behind the nav item that sits
         * after Spam.
         *
         * `search` is registered BEFORE the two profile routes for the reason `counts` is
         * registered before `{view}` below: a literal segment that could be read as a parameter
         * has to be claimed first. `customers/{customer}` and `companies/{company}` are
         * distinct segments and could not collide with it either way, but registration order is
         * the convention this file already runs on and mixing conventions is how one gets
         * forgotten.
         */
        Route::get('/company-customer/search', [CompanyCustomerController::class, 'search'])
            ->name('company-customer.search');
        Route::get('/company-customer/customers/{customer}', [CompanyCustomerController::class, 'customer'])
            ->whereNumber('customer')->name('company-customer.customer');
        Route::get('/company-customer/companies/{company}', [CompanyCustomerController::class, 'company'])
            ->whereNumber('company')->name('company-customer.company');
        Route::get('/company-customer', [CompanyCustomerController::class, 'index'])
            ->name('company-customer');

        /*
         * The Help Center's own queue (P21) — Inbox, Unassigned, Mine, Draft, Assigned, Closed
         * and Spam, across every active Space.
         *
         * These were chips inside one Space's Inbox screen. A URL each is what makes them
         * shareable, bookmarkable and survivable across a refresh — and what lets the
         * navigation light the one you are standing on.
         *
         * `counts` is registered BEFORE `{view}` and is not one of the view keys, so the
         * ordering is belt and braces rather than the only thing keeping them apart.
         */
        Route::get('/inbox', [InboxQueueController::class, 'show'])->name('inbox');
        Route::get('/inbox/counts', [InboxQueueController::class, 'counts'])->name('inbox.counts');

        /*
         * One view's rows as JSON (P67) — what a live update sends the cross-Space queue back for.
         *
         * BEFORE `/inbox/{view}` below. That route is enumerated, so `rows` would not match it
         * anyway; the ordering is belt and braces, exactly as the comment above `/inbox` says.
         *
         * The view is OPTIONAL and trails the literal, so `/inbox/rows` is the default queue and
         * `/inbox/rows/unassigned` is one of the named views — the same shape as the pages.
         */
        Route::get('/inbox/rows/{view?}', [InboxQueueController::class, 'rows'])
            ->whereIn('view', array_keys((array) config('help-center.request_views')))
            ->name('inbox.rows');

        /*
         * ENUMERATED from config, like every other segment in this file: an unknown view 404s
         * rather than rendering an empty queue that looks like a day with no work in it.
         *
         * `inbox` itself is excluded — it is the bare URL above, and accepting
         * /inbox/inbox as well would be two addresses for one screen.
         */
        Route::get('/inbox/{view}', [InboxQueueController::class, 'show'])
            ->whereIn('view', array_keys((array) config('help-center.request_views')))
            ->name('inbox.view');

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

        /*
         * The Inbox's rows as JSON (P67) — what a live update sends the screen back for.
         *
         * BEFORE `/spaces/{space}` so the literal segments are not swallowed by the wildcard,
         * the same ordering rule the routes above it follow.
         */
        Route::get('/spaces/{space}/inbox/rows', [SpaceController::class, 'rows'])
            ->whereNumber('space')->name('spaces.inbox.rows');

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

        /*
         * A Space's members (P10) — Add Member, edit their groups, remove.
         *
         * Nested under the Space for the same reason a Request's actions are: the membership
         * and the Space are checked together, so a member of another Space cannot be reached
         * through this Space's URL.
         */
        Route::post('/spaces/{space}/members', [SpaceMemberController::class, 'store'])
            ->whereNumber('space')->name('spaces.members.store');
        Route::patch('/spaces/{space}/members/{member}', [SpaceMemberController::class, 'update'])
            ->whereNumber('space')->whereNumber('member')->name('spaces.members.update');
        Route::delete('/spaces/{space}/members/{member}', [SpaceMemberController::class, 'destroy'])
            ->whereNumber('space')->whereNumber('member')->name('spaces.members.destroy');

        /*
         * A customer's own details (P33) — name, company, phone.
         *
         * NOT nested under a Space: a customer belongs to the workspace, because somebody who
         * writes to Billing on Monday and Support on Tuesday is one person. Nesting it would
         * make them one person per Space, which is the count the panel exists to get right.
         */
        Route::patch('/customers/{customer}', [CustomerController::class, 'update'])
            ->whereNumber('customer')->name('customers.update');

        /*
         * A company's own details (P75 §13). Workspace-scoped like the customer above it, and
         * for the same reason — one Acme, whichever Space the mail reached (HC-D51).
         */
        Route::patch('/companies/{company}', [CompanyController::class, 'update'])
            ->whereNumber('company')->name('companies.update');

        /*
         * A Space's Customer and Company custom fields (P18, P75 §2) — managed on
         * Settings → Company & Customer.
         *
         * `{kind}` is `customer` or `company`, constrained here rather than only in the
         * controller so an unknown one 404s at the router. It was `/company-fields`; the second
         * list arrived with P75 and one segment is better than a second near-identical trio of
         * routes.
         *
         * Nested under the Space for the same reason its tags are. ONE update endpoint carrying
         * the whole field: Save Changes and the row's Disable are both "this field is now
         * that", and a second shape would be a second answer to what a missing key means.
         */
        Route::post('/spaces/{space}/custom-fields/{kind}', [CustomFieldController::class, 'store'])
            ->whereNumber('space')->whereIn('kind', ['customer', 'company'])
            ->name('spaces.custom-fields.store');
        Route::patch('/spaces/{space}/custom-fields/{kind}/{field}', [CustomFieldController::class, 'update'])
            ->whereNumber(['space', 'field'])->whereIn('kind', ['customer', 'company'])
            ->name('spaces.custom-fields.update');
        Route::delete('/spaces/{space}/custom-fields/{kind}/{field}', [CustomFieldController::class, 'destroy'])
            ->whereNumber(['space', 'field'])->whereIn('kind', ['customer', 'company'])
            ->name('spaces.custom-fields.destroy');

        /*
         * Ticket Metadata Mapping (P75 §3–§4, §14).
         *
         * `seed` and `reprocess` are registered BEFORE `{mapping}`, which is load-bearing: a
         * numeric constraint on the parameter would also work, but registration order is what
         * the rest of this file already relies on and mixing the two conventions is how one of
         * them gets forgotten.
         */
        Route::post('/spaces/{space}/metadata-mappings/seed', [MetadataMappingController::class, 'seed'])
            ->whereNumber('space')->name('spaces.metadata-mappings.seed');
        Route::post('/spaces/{space}/metadata-mappings/reprocess', [MetadataMappingController::class, 'reprocess'])
            ->whereNumber('space')->name('spaces.metadata-mappings.reprocess');
        Route::post('/spaces/{space}/metadata-mappings', [MetadataMappingController::class, 'store'])
            ->whereNumber('space')->name('spaces.metadata-mappings.store');
        Route::patch('/spaces/{space}/metadata-mappings/{mapping}', [MetadataMappingController::class, 'update'])
            ->whereNumber(['space', 'mapping'])->name('spaces.metadata-mappings.update');
        Route::delete('/spaces/{space}/metadata-mappings/{mapping}', [MetadataMappingController::class, 'destroy'])
            ->whereNumber(['space', 'mapping'])->name('spaces.metadata-mappings.destroy');

        /*
         * A Space's tags (P14) — its own vocabulary, managed on Settings → Tag.
         *
         * Nested under the Space, like its members: the tag and the Space are checked together,
         * so a tag from another Space cannot be reached through this Space's URL. No PATCH —
         * renaming a tag is a question about every Request already carrying it, and P14 does
         * not answer it.
         */
        Route::post('/spaces/{space}/tags', [TagController::class, 'store'])
            ->whereNumber('space')->name('spaces.tags.store');
        Route::delete('/spaces/{space}/tags/{tag}', [TagController::class, 'destroy'])
            ->whereNumber('space')->whereNumber('tag')->name('spaces.tags.destroy');

        /*
         * A Request's row actions (P9, Request Actions).
         *
         * ONE endpoint, not one per action. Assigning, changing status, changing priority and
         * marking spam all mean "this Request changed"; a route each would be five places that
         * have to agree about who may write to a Request and what a write does to its waiting
         * clock. The Request id is nested under its Space so the two are checked together —
         * a Request from another Space cannot be reached through this Space's URL.
         */
        /*
         * The Request's own PAGE — the stable per-ticket URL (P46).
         *
         * `/spaces/{space}/requests/{request}` is the address a person can bookmark, paste to a
         * colleague or open in a new tab, exactly as `work-items.show` is for a work item. The
         * drawer's Expand links here, and "Copy Ticket Link" resolves to it.
         *
         * It is the bare URL and the JSON is the one that gained a suffix, not the other way
         * round: the thing a human can hold on to should have the plainest address.
         */
        Route::get('/spaces/{space}/requests/{request}', [SpaceController::class, 'request'])
            ->whereNumber(['space', 'request'])->name('spaces.requests.page');

        // The drawer's contents as JSON (P32). Was the bare URL until the page above took it.
        Route::get('/spaces/{space}/requests/{request}/detail', [RequestController::class, 'show'])
            ->whereNumber('space')->whereNumber('request')->name('spaces.requests.detail');
        /*
         * Answering the customer, and posting an internal update (P36).
         *
         * TWO endpoints, not one with a flag. The difference between them is whether an email
         * leaves the building, which is the single most consequential thing this module does —
         * a boolean deciding it is a boolean somebody will get wrong. `storeUpdate` has no
         * mailer at all; the separation is structural rather than conditional.
         */
        Route::post('/spaces/{space}/requests/{request}/reply', [RequestController::class, 'reply'])
            ->whereNumber('space')->whereNumber('request')->name('spaces.requests.reply');
        // Re-send a reply whose delivery failed (P64). POST because it sends mail; nested under
        // the message so the ticket and the row are checked together.
        Route::post('/spaces/{space}/requests/{request}/messages/{message}/retry', [RequestController::class, 'retry'])
            ->whereNumber(['space', 'request', 'message'])->name('spaces.requests.retry');

        /*
         * Download a file a customer emailed in (P66).
         *
         * GET, and nested under the Request so the ticket and the file are authorized together
         * — the controller checks that the attachment actually belongs to this Request, which
         * is what stops an id from one ticket being served through another.
         */
        Route::get('/spaces/{space}/requests/{request}/attachments/{attachment}', [RequestAttachmentController::class, 'show'])
            ->whereNumber(['space', 'request', 'attachment'])->name('spaces.requests.attachments.show');

        Route::post('/spaces/{space}/requests/{request}/updates', [RequestController::class, 'storeUpdate'])
            ->whereNumber('space')->whereNumber('request')->name('spaces.requests.updates.store');

        /*
         * Internal notes (P42) — private discussion between the team on a ticket.
         *
         * Their own controller, because nothing in it can email a customer: see the note at the
         * top of RequestNoteController for why that guarantee is structural rather than a flag.
         */
        Route::get('/spaces/{space}/mentionable-users', [RequestNoteController::class, 'mentionable'])
            ->whereNumber('space')->name('spaces.mentionable-users');
        Route::post('/spaces/{space}/requests/{request}/notes', [RequestNoteController::class, 'store'])
            ->whereNumber(['space', 'request'])->name('spaces.requests.notes.store');
        Route::patch('/spaces/{space}/requests/{request}/notes/{note}', [RequestNoteController::class, 'update'])
            ->whereNumber(['space', 'request', 'note'])->name('spaces.requests.notes.update');
        Route::delete('/spaces/{space}/requests/{request}/notes/{note}', [RequestNoteController::class, 'destroy'])
            ->whereNumber(['space', 'request', 'note'])->name('spaces.requests.notes.destroy');

        // Editing and withdrawing an internal update (P39). Nested under the Request, like every
        // other write to it, so the Space and the Request are checked together.
        Route::patch('/spaces/{space}/requests/{request}/updates/{update}', [RequestController::class, 'updateUpdate'])
            ->whereNumber(['space', 'request', 'update'])->name('spaces.requests.updates.update');
        Route::delete('/spaces/{space}/requests/{request}/updates/{update}', [RequestController::class, 'destroyUpdate'])
            ->whereNumber(['space', 'request', 'update'])->name('spaces.requests.updates.destroy');

        /*
         * A Space's email templates and signatures (P48).
         *
         * Their own routes rather than the one settings endpoint, because this section has four
         * verbs that endpoint does not have — preview, test, reset, and a second resource — and
         * writes to two tables of its own.
         *
         * `{type}` is validated against the config keys in the controller rather than in the
         * route, so an unknown type gives a 404 from the one place that knows what a type is.
         */
        // A Space's CSAT configuration (P56) — its own table, so its own endpoint.
        Route::put('/spaces/{space}/rating', [RatingController::class, 'update'])
            ->whereNumber('space')->name('spaces.rating.update');

        Route::put('/spaces/{space}/email-templates/{type}', [EmailTemplateController::class, 'update'])
            ->whereNumber('space')->name('spaces.email-templates.update');
        Route::delete('/spaces/{space}/email-templates/{type}', [EmailTemplateController::class, 'reset'])
            ->whereNumber('space')->name('spaces.email-templates.reset');
        Route::post('/spaces/{space}/email-templates/{type}/preview', [EmailTemplateController::class, 'preview'])
            ->whereNumber('space')->name('spaces.email-templates.preview');
        // Throttled: it sends real mail, and a form button that sends mail is a form button
        // somebody will hold down.
        Route::post('/spaces/{space}/email-templates/{type}/test', [EmailTemplateController::class, 'test'])
            ->whereNumber('space')->middleware('throttle:10,1')->name('spaces.email-templates.test');

        // Signatures. The `{user}`-less pair is the SPACE DEFAULT — see the controller for why
        // the two are one resource rather than two.
        Route::put('/spaces/{space}/signatures/default', [EmailTemplateController::class, 'saveSignature'])
            ->whereNumber('space')->name('spaces.signatures.default');
        Route::delete('/spaces/{space}/signatures/default', [EmailTemplateController::class, 'deleteSignature'])
            ->whereNumber('space')->name('spaces.signatures.default.destroy');
        Route::put('/spaces/{space}/signatures/{user}', [EmailTemplateController::class, 'saveSignature'])
            ->whereNumber(['space', 'user'])->name('spaces.signatures.update');
        Route::delete('/spaces/{space}/signatures/{user}', [EmailTemplateController::class, 'deleteSignature'])
            ->whereNumber(['space', 'user'])->name('spaces.signatures.destroy');

        /*
         * Snooze (P45) — its own pair of routes, not a field on the PATCH above.
         *
         * POST both sets and reschedules; DELETE is the manual Unsnooze. Nested under the Space
         * like every other write to a Request, and singular in the URL because the requirement
         * is explicit that this is per ticket and never a bulk action.
         */
        Route::post('/spaces/{space}/requests/{request}/snooze', [RequestSnoozeController::class, 'store'])
            ->whereNumber(['space', 'request'])->name('spaces.requests.snooze.store');
        Route::delete('/spaces/{space}/requests/{request}/snooze', [RequestSnoozeController::class, 'destroy'])
            ->whereNumber(['space', 'request'])->name('spaces.requests.snooze.destroy');

        Route::patch('/spaces/{space}/requests/{request}', [RequestController::class, 'update'])
            ->whereNumber('space')->whereNumber('request')->name('spaces.requests.update');

        // Archive is reversible and touches only this Space; delete takes everything under it.
        Route::patch('/spaces/{space}/archive', [SpaceController::class, 'archive'])
            ->whereNumber('space')->name('spaces.archive');
        Route::delete('/spaces/{space}', [SpaceController::class, 'destroy'])
            ->whereNumber('space')->name('spaces.destroy');

        /*
         * Space Settings — one routed page per section behind its own left-hand nav (P11).
         *
         * BEFORE the `{section}` route below, and that ordering is load-bearing: `settings` is
         * one of the Space's five sections, so `/spaces/11/settings` matches both, and the
         * first one registered wins. Registered here, the bare URL redirects into the first
         * settings page; registered after, it would render the old single-panel screen and
         * these routes would be dead.
         *
         * The section is ENUMERATED from config for the same reason the Space's own sections
         * are — an unknown segment 404s rather than rendering an empty shell, and the config
         * stays the single source of what Settings contains.
         */
        Route::get('/spaces/{space}/settings', [SpaceSettingsController::class, 'index'])
            ->whereNumber('space')->name('spaces.settings.index');

        Route::get('/spaces/{space}/settings/{setting}', [SpaceSettingsController::class, 'show'])
            ->whereNumber('space')
            ->whereIn('setting', array_column((array) config('help-center.space_settings_nav'), 'key'))
            ->name('spaces.settings');

        // One endpoint for every panel: each sends only the fields it renders, and
        // UpdateSpaceSettingRequest decides which those are from the section in the URL.
        Route::patch('/spaces/{space}/settings/{setting}', [SpaceSettingsController::class, 'update'])
            ->whereNumber('space')
            ->whereIn('setting', array_column((array) config('help-center.space_settings_nav'), 'key'))
            ->name('spaces.settings.update');

        /*
         * Where /spaces/{id}/workflow went (P15).
         *
         * BEFORE the `{section}` route, and needed because `workflow` is no longer one of the
         * Space's sections — without this the URL would 404 for anybody holding a link to it,
         * and the answer "it moved" is one a redirect can give and a 404 cannot.
         */
        Route::get('/spaces/{space}/workflow', fn (HelpCenterSpace $space) => redirect()->route(
            'help-center.spaces.settings',
            ['space' => $space->id, 'setting' => 'workflow'],
        ))->whereNumber('space')->name('spaces.workflow');

        /*
         * Where /spaces/{id}/members went — into Settings, beside the Inbox and Workflow pages.
         *
         * BEFORE the `{section}` route, and needed for the same reason the workflow redirect
         * above is: `members` is no longer one of the Space's sections, so without this the URL
         * would 404 for anybody holding a link to it — and the module emails those links. The
         * POST/PATCH/DELETE member endpoints above are untouched; only the screen moved.
         */
        Route::get('/spaces/{space}/members', fn (HelpCenterSpace $space) => redirect()->route(
            'help-center.spaces.settings',
            ['space' => $space->id, 'setting' => 'members'],
        ))->whereNumber('space')->name('spaces.members');

        /*
         * The Space Overview dashboard's data, as JSON (P50).
         *
         * BEFORE the `{section}` catch-all below, which would otherwise swallow `overview` and
         * try to render a section called "data".
         */
        Route::get('/spaces/{space}/overview/data', [SpaceController::class, 'overviewData'])
            ->whereNumber('space')->name('spaces.overview.data');

        /*
         * A Space's sections (P4/P9/P15/P22): Overview, Inbox, the six queues, Settings. ENUMERATED from config, so an unknown segment 404s rather than rendering an
         * empty screen, and so the config stays the single source of what a Space contains.
         *
         * This replaced /spaces/{space}/{view}, which routed the six CONVERSATION views. Those
         * are filters on the Conversations section now, not URLs of their own.
         */
        Route::get('/spaces/{space}/{section}', [SpaceController::class, 'section'])
            ->whereNumber('space')
            /*
             * `settings` is EXCLUDED, not merely shadowed by the route above.
             *
             * The ordering already means this never matches it, so this is belt and braces —
             * but the panel it would render was deleted when Settings became its own screen
             * (P11), so a reordering of this file would produce a blank page rather than a
             * clean 404. The list still comes from config; one entry is removed from it.
             */
            ->whereIn('section', array_values(array_merge(
                array_diff(array_keys((array) config('help-center.space_sections')), ['settings']),
                /*
                 * And the six queues (P22) — the same views the Help Center's own bar carries,
                 * narrowed to this Space. Enumerated from the SAME config the top-level routes
                 * use, so a view cannot exist at one scope and 404 at the other.
                 */
                array_keys((array) config('help-center.request_views')),
            )))
            ->name('spaces.section');

        // Email addresses on an Inbox (§6, §7).
        Route::post('/inboxes/{inbox}/addresses', [EmailAddressController::class, 'store'])
            ->whereNumber('inbox')->name('addresses.store');
        Route::delete('/inboxes/{inbox}/addresses/{address}', [EmailAddressController::class, 'destroy'])
            ->whereNumber(['inbox', 'address'])->name('addresses.destroy');

        /*
         * "Send test email" on an address that is not verified yet (P11).
         *
         * Nested under the address, not under the Space, because it probes ONE address — and the
         * address's Inbox is what decides where the probe is expected to come back to. The Space
         * Overview's whole-Inbox test keeps its own pair of routes above; both drive the same
         * InboundTestRunner, so there is one definition of what a passing test means.
         */
        Route::post('/inboxes/{inbox}/addresses/{address}/test', [EmailAddressController::class, 'test'])
            ->whereNumber(['inbox', 'address'])->name('addresses.test');
        Route::get('/inboxes/{inbox}/addresses/{address}/test', [EmailAddressController::class, 'testStatus'])
            ->whereNumber(['inbox', 'address'])->name('addresses.test.show');
    });
