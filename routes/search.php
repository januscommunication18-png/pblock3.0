<?php

use App\Http\Controllers\GlobalSearchController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Global search (docs/features/global-search.md) — loaded by routes/web.php
|--------------------------------------------------------------------------
| One endpoint, at the top level rather than under a project: the palette is
| opened from anywhere and searches the whole workspace. 'workspace.tenancy'
| puts the active workspace in context, which is what confines the
| tenant-scoped queries behind it.
|
| Throttled because it is called on a debounce while somebody types, so one
| user at a keyboard is a burst of requests rather than a single one.
*/

Route::middleware(['auth', 'workspace.tenancy'])
    ->group(function () {
        Route::get('/search', GlobalSearchController::class)
            ->middleware('throttle:120,1')
            ->name('search');
    });
