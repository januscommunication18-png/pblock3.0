<?php

use App\Http\Controllers\Onboarding\InviteController;
use App\Http\Controllers\Onboarding\WorkspaceController as OnboardingWorkspaceController;
use App\Http\Controllers\WelcomeController;
use App\Http\Controllers\Workspace\CreateWorkspaceController;
use App\Http\Controllers\Workspace\InviteMembersController;
use App\Http\Controllers\Workspace\SwitchWorkspaceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Phase 3 — Create Workspace routes
|--------------------------------------------------------------------------
| First-workspace onboarding (workspace + invite), additional workspace
| creation, and the post-onboarding welcome / get-started home.
| require __DIR__.'/workspace.php'; from routes/web.php
*/

Route::middleware('auth')->group(function () {
    // First-workspace onboarding (spec §3, §6)
    Route::get('/onboarding/workspace', [OnboardingWorkspaceController::class, 'show'])
        ->name('onboarding.workspace');
    Route::post('/onboarding/workspace', [OnboardingWorkspaceController::class, 'store'])
        ->middleware('throttle:12,1')
        ->name('onboarding.workspace.store');

    Route::get('/onboarding/invite', [InviteController::class, 'show'])->name('onboarding.invite');
    Route::post('/onboarding/invite', [InviteController::class, 'store'])
        ->middleware('throttle:12,1')
        ->name('onboarding.invite.store');
    Route::post('/onboarding/invite/skip', [InviteController::class, 'skip'])
        ->name('onboarding.invite.skip');

    // Additional workspace creation (spec §7)
    Route::get('/workspaces/create', [CreateWorkspaceController::class, 'show'])->name('workspaces.create');
    Route::post('/workspaces', [CreateWorkspaceController::class, 'store'])
        ->middleware('throttle:12,1')
        ->name('workspaces.store');
    Route::get('/workspaces/slug-available', [CreateWorkspaceController::class, 'slugAvailable'])
        ->name('workspaces.slug');

    // Invite teammates to the current workspace (spec §6), reachable from the app
    Route::get('/workspaces/invite', [InviteMembersController::class, 'show'])->name('workspaces.invite');
    Route::post('/workspaces/invite', [InviteMembersController::class, 'store'])
        ->middleware('throttle:12,1')
        ->name('workspaces.invite.store');

    // Switch the active workspace (spec §7 switcher)
    Route::post('/workspaces/{workspace}/switch', SwitchWorkspaceController::class)
        ->name('workspaces.switch');

    // Post-onboarding home
    Route::get('/welcome', [WelcomeController::class, 'show'])->name('welcome');
});
