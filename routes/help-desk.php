<?php

use App\Http\Controllers\HelpDesk\HelpDeskController;
use App\Http\Controllers\HelpDesk\InboxController;
use App\Http\Controllers\HelpDesk\MemberController;
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

Route::middleware(['auth', 'workspace.tenancy'])
    ->prefix('help-desk')
    ->name('help-desk.')
    ->group(function () {
        Route::get('/', [HelpDeskController::class, 'index'])->name('index');

        // Settings › Members (FR-1.4/1.6/1.7/1.8)
        Route::get('/settings/members', [MemberController::class, 'index'])->name('members');
        Route::post('/settings/members', [MemberController::class, 'store'])->name('members.store');
        Route::patch('/settings/members/{member}', [MemberController::class, 'update'])->name('members.update');
        Route::delete('/settings/members/{member}', [MemberController::class, 'destroy'])->name('members.destroy');

        // Inboxes (FR-1.7) — created and renamed here so inbox-level access has something to
        // grant. Their email channels and routing are Phase 2.
        Route::post('/settings/inboxes', [InboxController::class, 'store'])->name('inboxes.store');
        Route::patch('/settings/inboxes/{inbox}', [InboxController::class, 'update'])->name('inboxes.update');
    });
