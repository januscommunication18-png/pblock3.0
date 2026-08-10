<?php

use App\Http\Controllers\Project\ProjectController;
use App\Http\Controllers\Project\ProjectLabelController;
use App\Http\Controllers\Project\ProjectMembersController;
use App\Http\Controllers\Project\ProjectSettingsController;
use App\Http\Controllers\Project\ProjectStateController;
use App\Models\Project;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Phase 4 — Projects routes (loaded by routes/web.php)
|--------------------------------------------------------------------------
| Projects live under the active workspace. 'workspace.tenancy' initializes
| tenancy so Project bindings/queries are auto-confined to the workspace.
| This file is the one web.php requires (require __DIR__.'/project.php';) and
| now carries the full list + lifecycle + settings route set.
*/

Route::middleware(['auth', 'workspace.tenancy'])
    ->prefix('projects')
    ->name('projects.')
    ->group(function () {
        // List + create
        Route::get('/', [ProjectController::class, 'index'])->name('index');
        Route::post('/', [ProjectController::class, 'store'])->middleware('throttle:30,1')->name('store');
        Route::get('/identifier-available', [ProjectController::class, 'identifierAvailable'])->name('identifier');

        // Open + lifecycle
        Route::get('/{project}', [ProjectController::class, 'show'])->whereNumber('project')->name('show');
        Route::patch('/{project}/state', [ProjectController::class, 'setState'])->whereNumber('project')->name('state');
        Route::patch('/{project}/lead', [ProjectController::class, 'setLead'])->whereNumber('project')->name('lead');
        Route::patch('/{project}/priority', [ProjectController::class, 'setPriority'])->whereNumber('project')->name('priority');
        Route::patch('/{project}/dates', [ProjectController::class, 'setDates'])->whereNumber('project')->name('dates');
        Route::post('/{project}/archive', [ProjectController::class, 'archive'])->whereNumber('project')->name('archive');
        Route::post('/{project}/restore', [ProjectController::class, 'restore'])->whereNumber('project')->name('restore');
        Route::delete('/{project}', [ProjectController::class, 'destroy'])->whereNumber('project')->name('destroy');

        // Project settings actions
        Route::prefix('/{project}/settings')->name('settings.')->group(function () {
            Route::patch('/general', [ProjectSettingsController::class, 'updateGeneral'])->name('general.update');
            Route::post('/cover', [ProjectSettingsController::class, 'uploadCover'])->name('cover');
            Route::post('/features/toggle', [ProjectSettingsController::class, 'toggleFeature'])->name('features.toggle');

            Route::post('/members', [ProjectMembersController::class, 'store'])->name('members.store');
            Route::patch('/members/{member}/role', [ProjectMembersController::class, 'updateRole'])->name('members.role');
            Route::delete('/members/{member}', [ProjectMembersController::class, 'remove'])->name('members.remove');

            Route::post('/states', [ProjectStateController::class, 'store'])->name('states.store');
            Route::patch('/states/{state}', [ProjectStateController::class, 'update'])->name('states.update');
            Route::delete('/states/{state}', [ProjectStateController::class, 'destroy'])->name('states.destroy');

            Route::post('/labels', [ProjectLabelController::class, 'store'])->name('labels.store');
            Route::patch('/labels/{label}', [ProjectLabelController::class, 'update'])->name('labels.update');
            Route::delete('/labels/{label}', [ProjectLabelController::class, 'destroy'])->name('labels.destroy');
        });

        // Section pages — LAST so the static settings routes above win. GET only.
        Route::get('/{project}/settings', fn (Project $project) => redirect()->route('projects.settings', ['project' => $project->id, 'section' => 'general']))
            ->whereNumber('project')->name('settings.index');
        Route::get('/{project}/settings/{section}', [ProjectSettingsController::class, 'show'])
            ->whereNumber('project')->where('section', '[a-z-]+')->name('settings');
    });
