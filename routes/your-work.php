<?php

use App\Http\Controllers\YourWorkController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Your Work (docs/features/your-work.md) — loaded by routes/web.php
|--------------------------------------------------------------------------
| One person's work items across every project they can see, so like Drafts
| this sits at the top level rather than under /projects/{project}.
| 'workspace.tenancy' puts the active workspace in context, which is what
| confines the tenant-scoped queries behind it.
*/

Route::middleware(['auth', 'workspace.tenancy'])->group(function () {
    // The tab is a path segment, not a query string: each tab is a place you can link
    // somebody to, and the back button should move between them. Whitelisted in the
    // controller, so an unknown tab 404s rather than rendering an empty screen.
    Route::get('/your-work/{tab?}', [YourWorkController::class, 'show'])
        ->where('tab', '[a-z-]+')
        ->name('your-work');
});
