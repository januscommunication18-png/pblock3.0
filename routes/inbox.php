<?php

use App\Http\Controllers\InboxController;
use App\Models\InboxNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Inbox (docs/features/inbox.md) — loaded by routes/web.php
|--------------------------------------------------------------------------
| A personal stream rather than a project one, so it sits at the top level
| beside Drafts and Your Work. 'workspace.tenancy' puts the active workspace
| in context, which confines the tenant-scoped queries behind it.
*/

Route::middleware(['auth', 'workspace.tenancy'])
    ->prefix('inbox')
    ->name('inbox.')
    ->group(function () {
        /*
         * Bound to the SIGNED-IN user's own notifications.
         *
         * Somebody else's id simply is not found — the ownership rule (§31) is enforced before
         * the controller, as a 404 that never confirms the notification exists, rather than as
         * a check each action has to remember.
         */
        Route::bind('notification', fn (string $id) => InboxNotification::query()
            ->for(Auth::id())->findOrFail($id));

        Route::get('/', [InboxController::class, 'index'])->name('index');
        Route::get('/list', [InboxController::class, 'list'])->name('list');
        Route::post('/read-all', [InboxController::class, 'readAll'])
            ->middleware('throttle:60,1')->name('read-all');
        Route::post('/{notification}/read', [InboxController::class, 'read'])
            ->whereNumber('notification')->name('read');
    });
