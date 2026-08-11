<?php

use App\Http\Controllers\Invitation\InvitationController;
use App\Http\Controllers\Invitation\PendingInvitationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Member invite flow — acceptance & membership activation
|--------------------------------------------------------------------------
| The public invitation link plus the signed-in join step. Deliberately outside
| both the 'guest' and 'auth' groups for /invite/{token}: the same link has to
| work for a stranger, for the invited person already signed in, and for someone
| signed in as a different account (invite spec §18, §52–§54).
| require __DIR__.'/invitation.php'; from routes/web.php
*/

// Public invitation link (§13 URL shape: no ids, just the token).
Route::get('/invite/{token}', [InvitationController::class, 'show'])
    ->where('token', '[A-Za-z0-9]{16,128}')
    ->middleware('throttle:30,1')
    ->name('invitations.show');

Route::post('/invite/{token}/start', [InvitationController::class, 'start'])
    ->where('token', '[A-Za-z0-9]{16,128}')
    ->middleware('throttle:10,1')
    ->name('invitations.start');

Route::post('/invite/{token}/accept', [InvitationController::class, 'accept'])
    ->where('token', '[A-Za-z0-9]{16,128}')
    ->middleware(['auth', 'throttle:10,1'])
    ->name('invitations.accept');

// Signed-in join step: onboarding hands over here instead of to Create Workspace (§29).
Route::middleware('auth')->group(function () {
    Route::get('/invitations/pending', [PendingInvitationController::class, 'show'])
        ->name('invitations.pending');
    Route::post('/invitations/pending', [PendingInvitationController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('invitations.join');
    Route::get('/invitations/joined', [PendingInvitationController::class, 'joined'])
        ->name('invitations.joined');
});
