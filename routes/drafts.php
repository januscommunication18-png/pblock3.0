<?php

use App\Http\Controllers\DraftController;
use App\Http\Controllers\DraftMediaController;
use App\Models\WorkItem;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Drafts (docs/features/drafts.md) — loaded by routes/web.php
|--------------------------------------------------------------------------
| Work items captured before they have a project, so these sit at the top
| level rather than under /projects/{project}. 'workspace.tenancy' puts the
| active workspace in context, which is what confines the tenant-scoped
| WorkItem queries below to it.
*/

Route::middleware(['auth', 'workspace.tenancy'])
    ->prefix('drafts')
    ->name('drafts.')
    ->group(function () {
        /*
         * Resolved explicitly, for two reasons that both matter.
         *
         * Implicit binding would find nothing at all: the ExcludesDrafts global scope hides
         * every draft from an ordinary WorkItem query, so `WorkItem $draft` would 404 on the
         * author's own drafts. `drafts()` is the one query that sees them.
         *
         * And it takes the signed-in user's id, so a draft belonging to somebody else is
         * simply not found — the privacy rule (§4) is enforced before the controller, as a
         * 404 that never confirms the draft exists (DR-06), rather than as a check each
         * action has to remember.
         */
        Route::bind('draft', fn (string $id) => WorkItem::drafts(Auth::id())->findOrFail($id));

        // Description editor media. ABOVE the /{draft} routes so "media" is never read as a
        // draft id, the same ordering the project media routes use.
        Route::post('/media', [DraftMediaController::class, 'store'])
            ->middleware('throttle:60,1')->name('media.store');
        Route::get('/media', [DraftMediaController::class, 'index'])->name('media.index');
        Route::get('/media/{media}', [DraftMediaController::class, 'show'])
            ->whereNumber('media')->name('media.show');

        Route::get('/', [DraftController::class, 'index'])->name('index');
        Route::post('/', [DraftController::class, 'store'])->middleware('throttle:60,1')->name('store');
        Route::patch('/{draft}', [DraftController::class, 'update'])->whereNumber('draft')->name('update');
        Route::post('/{draft}/publish', [DraftController::class, 'publish'])
            ->whereNumber('draft')->middleware('throttle:60,1')->name('publish');
        Route::delete('/{draft}', [DraftController::class, 'destroy'])->whereNumber('draft')->name('destroy');
    });
