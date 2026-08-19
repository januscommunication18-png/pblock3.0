<?php

use App\Http\Controllers\HelpDesk\ActivityController;
use App\Http\Controllers\HelpDesk\ConversationController;
use App\Http\Controllers\HelpDesk\DeliveryEventController;
use App\Http\Controllers\HelpDesk\EmailAddressController;
use App\Http\Controllers\HelpDesk\HelpDeskController;
use App\Http\Controllers\HelpDesk\InboundEmailController;
use App\Http\Controllers\HelpDesk\InboxController;
use App\Http\Controllers\HelpDesk\MemberController;
use App\Http\Controllers\HelpDesk\PostmarkInboundController;
use App\Http\Controllers\HelpDesk\SetupController;
use App\Http\Controllers\HelpDesk\SpaceController;
use App\Http\Controllers\HelpDesk\SpaceSetupController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Help Desk (docs/features/help-desk.md) — loaded by routes/web.php
|--------------------------------------------------------------------------
| Workspace-level, like Wiki, rather than nested under a project: a Help
| Desk belongs to the organization and not to any one piece of delivery
| work. 'workspace.tenancy' puts the active workspace in context, which
| confines the tenant-scoped queries behind it — and confines route model
| binding for members and inboxes to this workspace as well.
|
| Whether the app is switched on, and whether this person may be in here at
| all, is checked in the controller rather than by a route middleware —
| Phase 1 §13 requires server-side authorization, and putting it where the
| data is read keeps it true for every action added to this group later.
*/

/*
 * Inbound email (FR-2.4, decision H19).
 *
 * OUTSIDE the authenticated group, necessarily: the caller is a mail provider, not a person.
 * It carries none of the session machinery either — no CSRF token (excluded in
 * bootstrap/app.php), no access-code gate (excluded in config/access_gate.php) and no idle
 * timeout to trip. What stands in for all of it is the body signature the controller checks.
 *
 * Throttled hard: this is the one endpoint the outside world can reach, and a mail loop is a
 * denial of service that arrives as ordinary traffic.
 */
Route::post('help-desk/email/inbound', InboundEmailController::class)
    ->middleware('throttle:120,1')
    ->name('help-desk.email.inbound');

/*
 * Postmark Inbound (Inbound Email requirements §3 — the Phase 1 provider).
 *
 * A second door onto the same ingestion, and it exists because Postmark does not sign inbound
 * webhooks: the documented practice is a secret in the URL. So the secret IS the path, compared
 * in constant time, and unset means the endpoint refuses everything — the same rule the generic
 * endpoint follows without a secret, because "not configured" must never mean "accepts anything".
 *
 * Everything past authentication is shared: the payload is mapped onto the normalized shape and
 * handed to the same job. A provider gets to decide what its webhook looks like, not what this
 * application's ingestion is.
 */
Route::post('help-desk/email/inbound/postmark/{token}', PostmarkInboundController::class)
    ->middleware('throttle:120,1')
    ->name('help-desk.email.inbound.postmark');

// The same provider reporting what happened to what we sent (FR-2.9), authenticated the same
// way and for the same reasons.
Route::post('help-desk/email/delivery', DeliveryEventController::class)
    ->middleware('throttle:240,1')
    ->name('help-desk.email.delivery');

Route::middleware(['auth', 'workspace.tenancy'])
    ->prefix('help-desk')
    ->name('help-desk.')
    ->group(function () {
        Route::get('/', [HelpDeskController::class, 'index'])->name('index');

        /*
         * Conversations (Phase 2, FR-2.8). A list and a move, which is what routing needs to be
         * real; reading and replying are the Phase 3 conversation workspace.
         */
        Route::get('/conversations', [ConversationController::class, 'index'])->name('conversations');
        Route::patch('/conversations/{conversation}/inbox', [ConversationController::class, 'move'])
            ->name('conversations.move');

        /*
         * Spaces (Workspace & Inbox Assignment requirements §5, §8, §9, §13).
         *
         * The layer between the Help Desk and its inboxes: a brand, a business unit or a region
         * with its own inboxes and conversations. Called a space rather than a workspace because
         * `Workspace` is already the tenant (decision H37).
         *
         * `/spaces/new` and `/spaces/switch` are declared BEFORE `/spaces/{space}`, or "new"
         * would be read as the id of a space nobody has.
         */
        Route::get('/spaces', [SpaceController::class, 'index'])->name('spaces');
        Route::get('/spaces/new', [SpaceController::class, 'create'])->name('spaces.create');
        Route::post('/spaces/switch', [SpaceController::class, 'switch'])->name('spaces.switch');
        Route::post('/spaces', [SpaceController::class, 'store'])->name('spaces.store');
        Route::get('/spaces/{space}', [SpaceController::class, 'show'])->name('spaces.show');
        Route::patch('/spaces/{space}', [SpaceController::class, 'update'])->name('spaces.update');
        Route::post('/spaces/{space}/inboxes', [SpaceController::class, 'assign'])->name('spaces.assign');
        Route::delete('/spaces/{space}/inboxes/{inbox}', [SpaceController::class, 'unassign'])->name('spaces.unassign');
        Route::post('/spaces/{space}/archive', [SpaceController::class, 'archive'])->name('spaces.archive');
        Route::post('/spaces/{space}/restore', [SpaceController::class, 'restore'])->name('spaces.restore');

        /*
         * Continue to Setup Inbox (the Setup Inbox flow) — a full-page stepper, not a modal.
         *
         * Every step has an endpoint of its own because every step COMMITS as it is taken: the
         * inbox exists after step 1, each address after it is added, each invitation when it is
         * sent. That is what makes progress resumable — there is no draft to lose.
         */
        Route::get('/spaces/{space}/setup-inbox', [SpaceSetupController::class, 'index'])->name('spaces.setup');
        Route::post('/spaces/{space}/setup-inbox/name', [SpaceSetupController::class, 'name'])->name('spaces.setup.name');
        Route::post('/spaces/{space}/setup-inbox/addresses', [SpaceSetupController::class, 'addAddress'])->name('spaces.setup.addresses');
        Route::patch('/spaces/{space}/setup-inbox/addresses/{emailAddress}', [SpaceSetupController::class, 'updateAddress'])
            ->name('spaces.setup.addresses.update');
        Route::delete('/spaces/{space}/setup-inbox/addresses/{emailAddress}', [SpaceSetupController::class, 'removeAddress'])
            ->name('spaces.setup.addresses.destroy');
        Route::post('/spaces/{space}/setup-inbox/verify', [SpaceSetupController::class, 'verify'])->name('spaces.setup.verify');
        Route::post('/spaces/{space}/setup-inbox/connect', [SpaceSetupController::class, 'connect'])->name('spaces.setup.connect');
        // Throttled like every other endpoint that sends mail to an address the caller chooses.
        Route::post('/spaces/{space}/setup-inbox/team', [SpaceSetupController::class, 'addTeamMember'])
            ->middleware('throttle:12,1')->name('spaces.setup.team');
        Route::post('/spaces/{space}/setup-inbox/finish', [SpaceSetupController::class, 'finish'])->name('spaces.setup.finish');

        // First-time setup wizard (FR-1.3). Ahead of /settings/* so its own paths are matched
        // by this controller rather than read as a settings section.
        Route::get('/setup', [SetupController::class, 'index'])->name('setup');
        Route::patch('/setup', [SetupController::class, 'update'])->name('setup.update');
        Route::post('/setup/complete', [SetupController::class, 'complete'])->name('setup.complete');

        // Settings › Members (FR-1.4/1.6/1.7/1.8)
        Route::get('/settings/members', [MemberController::class, 'index'])->name('members');
        Route::post('/settings/members', [MemberController::class, 'store'])->name('members.store');
        Route::patch('/settings/members/{member}', [MemberController::class, 'update'])->name('members.update');
        Route::delete('/settings/members/{member}', [MemberController::class, 'destroy'])->name('members.destroy');

        // Invite a coworker who is not in the workspace yet (FR-1.5). Throttled like the
        // workspace's own invite endpoint — it sends email to an address the caller chooses.
        Route::post('/settings/invites', [MemberController::class, 'invite'])
            ->middleware('throttle:12,1')->name('invites.store');
        Route::delete('/settings/invites/{invite}', [MemberController::class, 'revokeInvite'])->name('invites.destroy');

        // Settings › Activity (FR-1.9) — read-only, by construction.
        Route::get('/settings/activity', [ActivityController::class, 'index'])->name('activity');

        /*
         * Settings › Inboxes (FR-1.7, and Phase 2's FR-2.1/2.2/2.3/2.5).
         *
         * No delete: an inbox holds conversations, and what happens to them when it goes is a
         * product question this phase does not answer.
         */
        Route::get('/settings/inboxes', [InboxController::class, 'index'])->name('inboxes');
        Route::post('/settings/inboxes', [InboxController::class, 'store'])->name('inboxes.store');
        Route::patch('/settings/inboxes/{inbox}', [InboxController::class, 'update'])->name('inboxes.update');

        /*
         * The inbox's generated inbound address (Inbound Email requirements §2).
         *
         * A route of its own rather than a field on the inbox form, because regenerating is not
         * a setting — it breaks every forwarding rule pointing at the old address, in mail
         * providers this application cannot see. "Read-only, except through an explicit
         * administrative action" is not a comment if the action has its own endpoint.
         */
        Route::post('/settings/inboxes/{inbox}/inbound-address', [InboxController::class, 'regenerateAddress'])
            ->name('inboxes.address.regenerate');

        /*
         * Settings › Inboxes › Email Addresses (Inbound Email requirements §4–§10).
         *
         * `…/email-addresses/new` is a PAGE, not a modal, and that is the requirement rather
         * than a preference (§4): connecting an address means leaving to configure forwarding in
         * another system and coming back, so the screen has to survive a refresh, a bookmark and
         * the Back button.
         */
        Route::get('/settings/inboxes/{inbox}/email-addresses', [EmailAddressController::class, 'index'])
            ->name('inboxes.addresses');
        Route::get('/settings/inboxes/{inbox}/email-addresses/new', [EmailAddressController::class, 'create'])
            ->name('inboxes.addresses.create');
        Route::post('/settings/inboxes/{inbox}/email-addresses', [EmailAddressController::class, 'store'])
            ->name('inboxes.addresses.store');
        Route::post('/settings/inboxes/{inbox}/email-addresses/{emailAddress}/verify', [EmailAddressController::class, 'verify'])
            ->name('inboxes.addresses.verify');
        Route::patch('/settings/inboxes/{inbox}/email-addresses/{emailAddress}', [EmailAddressController::class, 'update'])
            ->name('inboxes.addresses.update');
        Route::delete('/settings/inboxes/{inbox}/email-addresses/{emailAddress}', [EmailAddressController::class, 'destroy'])
            ->name('inboxes.addresses.destroy');
    });
