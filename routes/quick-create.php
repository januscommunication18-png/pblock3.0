<?php

use App\Http\Controllers\WorkItemQuickCreateController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Quick create (docs/features/quick-create.md) — loaded by routes/web.php
|--------------------------------------------------------------------------
| "Which projects may I add work to?" is a question about the person, not
| about a project, so it lives at the top level. The per-project half sits in
| routes/project.php beside the other work item routes.
*/

Route::middleware(['auth', 'workspace.tenancy'])->group(function () {
    Route::get('/work-items/create-options', [WorkItemQuickCreateController::class, 'projects'])
        ->name('work-items.create-options');
});
