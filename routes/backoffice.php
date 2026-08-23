<?php

use App\Http\Controllers\Backoffice\AdminController;
use App\Http\Controllers\Backoffice\ClientController;
use App\Http\Controllers\Backoffice\DashboardController;
use App\Http\Controllers\Backoffice\LoginController;
use App\Http\Controllers\Backoffice\PasswordResetController;
use App\Http\Controllers\Backoffice\SecurityVerificationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Back Office (docs/features/backoffice-auth.md)
|--------------------------------------------------------------------------
|
| Authorized Email → One-Time Email Code → Password → Back Office.
|
| Every route is throttled (§10). The numbers are the requirement's recommended limits, and they
| are on the ROUTES rather than inside the controllers so that a route added later cannot be
| reachable without one — the same reasoning `RequireAccessCode` is registered on the whole web
| group rather than on the auth routes.
|
| The throttle keys are per-IP by default, which is the right unit here: the endpoints below are
| unauthenticated, so there is no user to key on, and keying on the submitted email would let an
| attacker lock a known administrator out by spamming their address.
*/

Route::prefix('backoffice')->name('backoffice.')->group(function () {

    /* ---- Step 1: the authorized email (§2) ---- */
    Route::get('/', [SecurityVerificationController::class, 'show'])->name('verify.show');
    Route::post('/', [SecurityVerificationController::class, 'store'])
        ->middleware('throttle:10,1')->name('verify.store');

    /* ---- Step 2: the one-time code (§3) ---- */
    Route::get('/verify-code', [SecurityVerificationController::class, 'code'])->name('verify.code');
    // 5 attempts a minute against the code screen. The code itself independently dies after 5
    // wrong guesses (AuthCodeService), so this is the outer bound on guessing rate and that is
    // the inner bound on total guesses.
    Route::post('/verify-code', [SecurityVerificationController::class, 'verify'])
        ->middleware('throttle:5,1')->name('verify.submit');
    /*
     * Resend: the requirement's 60-second cooldown and 5-per-hour cap, as two throttles.
     *
     * Both are needed and neither implies the other — `1,1` alone would allow 60 an hour, and
     * `5,60` alone would allow five in five seconds.
     */
    Route::post('/resend-code', [SecurityVerificationController::class, 'resend'])
        ->middleware(['throttle:1,1', 'throttle:5,60'])->name('verify.resend');

    /* ---- Step 3: the password (§5) ----
       Behind `backoffice.verified`, which is the middleware that makes §4's "should not be able
       to access /backoffice/login directly" true. */
    Route::middleware('backoffice.verified')->group(function () {
        Route::get('/login', [LoginController::class, 'show'])->name('login.show');
        Route::post('/login', [LoginController::class, 'store'])
            ->middleware('throttle:5,1')->name('login.store');
    });

    /* ---- Forgot / reset (§6) ----
       NOT behind `backoffice.verified`: somebody who cannot get past the login screen because
       they have forgotten their password still needs this, and the reset link in their mailbox
       is its own proof. The reset form itself is reached from an emailed token, so it cannot be
       behind a session gate at all. */
    Route::get('/forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'email'])
        ->middleware('throttle:5,10')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'reset'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])
        ->middleware('throttle:5,10')->name('password.update');

    /* ---- Signed in (§9) ---- */
    Route::middleware(['auth:backoffice', 'backoffice.timeout'])->group(function () {
        Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

        /*
         * Clients (docs/features/backoffice-clients.md, §25) — Phase 1's module.
         *
         * The three administrative actions are POSTs, not links: each one changes state, and a
         * GET that disables a tenant is one a crawler or a prefetching browser can trigger.
         */
        Route::get('/clients', [ClientController::class, 'index'])->name('clients.index');
        Route::get('/clients/{client}', [ClientController::class, 'show'])
            ->whereNumber('client')->name('clients.show');
        Route::post('/clients/{client}/reset-password', [ClientController::class, 'resetPassword'])
            ->whereNumber('client')->middleware('throttle:10,1')->name('clients.reset-password');
        Route::post('/clients/{client}/disable', [ClientController::class, 'disable'])
            ->whereNumber('client')->name('clients.disable');
        Route::post('/clients/{client}/enable', [ClientController::class, 'enable'])
            ->whereNumber('client')->name('clients.enable');
        Route::post('/clients/{client}/delete', [ClientController::class, 'destroy'])
            ->whereNumber('client')->name('clients.delete');
        Route::post('/clients/{client}/restore', [ClientController::class, 'restore'])
            ->whereNumber('client')->name('clients.restore');

        /*
         * Tenant-scoped actions (§ "Client Actions").
         *
         * Their own URLs under the client, so the address itself says which tenant is being
         * changed — the requirement's whole concern is an administrator meaning to modify one
         * workspace and reaching the switch that closes all of them.
         *
         * `{tenant}` is a UUID (tenants use stancl's string keys), so NO `whereNumber` here —
         * constraining it to digits would 404 every real tenant.
         */
        Route::get('/clients/{client}/tenants/{tenant}', [ClientController::class, 'tenant'])
            ->whereNumber('client')->name('clients.tenant');
        Route::post('/clients/{client}/tenants/{tenant}/access', [ClientController::class, 'tenantAccess'])
            ->whereNumber('client')->name('clients.tenant.access');
        Route::post('/clients/{client}/tenants/{tenant}/role', [ClientController::class, 'tenantRole'])
            ->whereNumber('client')->name('clients.tenant.role');
        Route::post('/clients/{client}/tenants/{tenant}/remove', [ClientController::class, 'tenantRemove'])
            ->whereNumber('client')->name('clients.tenant.remove');

        /*
         * Managing administrators (§8) — Super Admin only.
         *
         * The gate is `can:manage-backoffice-admins`, defined in AppServiceProvider, so the
         * answer lives beside every other authorization answer rather than in a string here.
         * The controller checks again, and the last-Super-Admin invariant is on the model
         * (BO-D6): three doors onto one rule, because it is the rule that can lock everybody
         * out of the platform for good.
         */
        Route::middleware('can:manage-backoffice-admins')->group(function () {
            Route::get('/admins', [AdminController::class, 'index'])->name('admins.index');
            Route::post('/admins', [AdminController::class, 'store'])->name('admins.store');
            Route::patch('/admins/{admin}', [AdminController::class, 'update'])
                ->whereNumber('admin')->name('admins.update');
        });
    });
});
