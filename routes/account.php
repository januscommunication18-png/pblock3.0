<?php

use App\Http\Controllers\Account\PasswordController;
use App\Http\Controllers\Account\PreferenceController;
use App\Http\Controllers\Account\ProfileController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Account routes — the signed-in user's own profile
|--------------------------------------------------------------------------
| Deliberately NOT under 'workspace.tenancy'. A user is central, not tenant-owned: the same
| person belongs to several workspaces and their name and face follow them across all of them.
| Initializing tenancy here would scope a row that has no tenant.
|
| None of these take a user id. They act on the authenticated user, so there is no id to
| tamper with — except `account.image`, which names a user because it serves someone ELSE's
| avatar to whoever can see them, and guards that in the controller.
*/

Route::middleware('auth')->group(function () {
    Route::patch('/account/profile', [ProfileController::class, 'update'])->name('account.profile.update');

    Route::post('/account/profile/image', [ProfileController::class, 'uploadImage'])
        ->middleware('throttle:30,1')->name('account.profile.image.store');
    Route::delete('/account/profile/image', [ProfileController::class, 'destroyImage'])
        ->name('account.profile.image.destroy');

    // Language & Time. WORKSPACE settings behind a personal menu, owner/admin only — the gate
    // is on the form request and re-checked when the tab is rendered.
    Route::patch('/account/preference', [PreferenceController::class, 'update'])->name('account.preference.update');

    // Change password. Throttled: this endpoint takes the current password, so an unthrottled
    // one is an oracle for guessing it from inside a hijacked session.
    Route::patch('/account/password', [PasswordController::class, 'update'])
        ->middleware('throttle:10,1')->name('account.password.update');

    Route::get('/account/image/{user}/{kind}', [ProfileController::class, 'image'])
        ->whereNumber('user')->whereIn('kind', ['avatar', 'cover'])->name('account.image');
});
