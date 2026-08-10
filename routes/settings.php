<?php

use App\Http\Controllers\Settings\CustomersSettingsController;
use App\Http\Controllers\Settings\GeneralSettingsController;
use App\Http\Controllers\Settings\InitiativesSettingsController;
use App\Http\Controllers\Settings\MembersSettingsController;
use App\Http\Controllers\Settings\PlaceholderSettingsController;
use App\Http\Controllers\Settings\ProjectsSettingsController;
use App\Http\Controllers\Settings\ReleasesSettingsController;
use App\Http\Controllers\Settings\TeamspacesSettingsController;
use App\Http\Controllers\Settings\WikiSettingsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Phase 3 — Workspace Settings routes
|--------------------------------------------------------------------------
| Owner/admin workspace administration for the active workspace. Tenancy is
| initialized to the current workspace by 'workspace.tenancy', so tenant-scoped
| settings models are auto-confined. require __DIR__.'/settings.php'; from web.php
*/

Route::middleware(['auth', 'workspace.tenancy'])
    ->prefix('settings')
    ->name('settings.')
    ->group(function () {
        Route::redirect('/', '/settings/general')->name('index');

        // Administration > General (spec §4)
        Route::get('/general', [GeneralSettingsController::class, 'show'])->name('general');
        Route::patch('/general', [GeneralSettingsController::class, 'update'])->name('general.update');
        Route::post('/general/logo', [GeneralSettingsController::class, 'logo'])->name('general.logo');
        Route::delete('/general', [GeneralSettingsController::class, 'destroy'])->name('general.destroy');

        // Administration > Members (spec §5)
        Route::get('/members', [MembersSettingsController::class, 'show'])->name('members');
        Route::post('/members/invite', [MembersSettingsController::class, 'invite'])
            ->middleware('throttle:12,1')->name('members.invite');
        Route::patch('/members/{membership}/role', [MembersSettingsController::class, 'updateRole'])->name('members.role');
        Route::delete('/members/{membership}', [MembersSettingsController::class, 'remove'])->name('members.remove');
        Route::delete('/members/invites/{invitation}', [MembersSettingsController::class, 'revoke'])->name('members.revoke');

        // Features > Projects (spec §6)
        Route::get('/projects', [ProjectsSettingsController::class, 'show'])->name('projects');
        Route::post('/projects/toggle', [ProjectsSettingsController::class, 'toggle'])->name('projects.toggle');
        Route::post('/projects/states', [ProjectsSettingsController::class, 'storeState'])->name('projects.states.store');
        Route::patch('/projects/states/{state}', [ProjectsSettingsController::class, 'updateState'])->name('projects.states.update');
        Route::delete('/projects/states/{state}', [ProjectsSettingsController::class, 'destroyState'])->name('projects.states.destroy');
        Route::post('/projects/labels', [ProjectsSettingsController::class, 'storeLabel'])->name('projects.labels.store');
        Route::patch('/projects/labels/{label}', [ProjectsSettingsController::class, 'updateLabel'])->name('projects.labels.update');
        Route::delete('/projects/labels/{label}', [ProjectsSettingsController::class, 'destroyLabel'])->name('projects.labels.destroy');
        Route::post('/projects/priorities', [ProjectsSettingsController::class, 'storePriority'])->name('projects.priorities.store');
        Route::patch('/projects/priorities/{priority}', [ProjectsSettingsController::class, 'updatePriority'])->name('projects.priorities.update');
        Route::delete('/projects/priorities/{priority}', [ProjectsSettingsController::class, 'destroyPriority'])->name('projects.priorities.destroy');

        // Products > Wiki (spec §7)
        Route::get('/wiki', [WikiSettingsController::class, 'show'])->name('wiki');
        Route::post('/wiki/toggle', [WikiSettingsController::class, 'toggle'])->name('wiki.toggle');
        Route::post('/wiki/labels', [WikiSettingsController::class, 'storeLabel'])->name('wiki.labels.store');
        Route::patch('/wiki/labels/{label}', [WikiSettingsController::class, 'updateLabel'])->name('wiki.labels.update');
        Route::delete('/wiki/labels/{label}', [WikiSettingsController::class, 'destroyLabel'])->name('wiki.labels.destroy');

        // Features > Releases (spec §8)
        Route::get('/releases', [ReleasesSettingsController::class, 'show'])->name('releases');
        Route::post('/releases/toggle', [ReleasesSettingsController::class, 'toggle'])->name('releases.toggle');
        Route::post('/releases/tags', [ReleasesSettingsController::class, 'storeTag'])->name('releases.tags.store');
        Route::patch('/releases/tags/{tag}', [ReleasesSettingsController::class, 'updateTag'])->name('releases.tags.update');
        Route::delete('/releases/tags/{tag}', [ReleasesSettingsController::class, 'destroyTag'])->name('releases.tags.destroy');
        Route::post('/releases/labels', [ReleasesSettingsController::class, 'storeLabel'])->name('releases.labels.store');
        Route::patch('/releases/labels/{label}', [ReleasesSettingsController::class, 'updateLabel'])->name('releases.labels.update');
        Route::delete('/releases/labels/{label}', [ReleasesSettingsController::class, 'destroyLabel'])->name('releases.labels.destroy');

        // Features > Initiatives (spec §9)
        Route::get('/initiatives', [InitiativesSettingsController::class, 'show'])->name('initiatives');
        Route::post('/initiatives/toggle', [InitiativesSettingsController::class, 'toggle'])->name('initiatives.toggle');
        Route::post('/initiatives/labels', [InitiativesSettingsController::class, 'storeLabel'])->name('initiatives.labels.store');
        Route::patch('/initiatives/labels/{label}', [InitiativesSettingsController::class, 'updateLabel'])->name('initiatives.labels.update');
        Route::delete('/initiatives/labels/{label}', [InitiativesSettingsController::class, 'destroyLabel'])->name('initiatives.labels.destroy');

        // Features > Teamspaces (spec §10)
        Route::get('/teamspaces', [TeamspacesSettingsController::class, 'show'])->name('teamspaces');
        Route::post('/teamspaces/toggle', [TeamspacesSettingsController::class, 'toggle'])->name('teamspaces.toggle');

        // Features > Customers (spec §11)
        Route::get('/customers', [CustomersSettingsController::class, 'show'])->name('customers');
        Route::post('/customers/toggle', [CustomersSettingsController::class, 'toggle'])->name('customers.toggle');
        Route::post('/customers/properties', [CustomersSettingsController::class, 'storeProperty'])->name('customers.properties.store');
        Route::patch('/customers/properties/{property}', [CustomersSettingsController::class, 'updateProperty'])->name('customers.properties.update');
        Route::delete('/customers/properties/{property}', [CustomersSettingsController::class, 'destroyProperty'])->name('customers.properties.destroy');

        // Coming-Soon + placeholder sections (spec §12) — must stay LAST so the static
        // active routes above win; unknown keys 404 inside the controller.
        Route::get('/{section}', [PlaceholderSettingsController::class, 'show'])
            ->where('section', '[a-z-]+')->name('placeholder');
    });
