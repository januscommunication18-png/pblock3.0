<?php

use App\Http\Controllers\Auth\AccessGateController;
use App\Http\Controllers\Auth\EmailSignupController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\Auth\SignInController;
use App\Http\Controllers\Auth\SocialAuthController;
use App\Http\Controllers\Auth\SsoController;
use App\Http\Controllers\Auth\VerifyCodeController;
use App\Http\Controllers\Dev\EmailLogController;
use App\Http\Controllers\Onboarding\AvatarController;
use App\Http\Controllers\Onboarding\GoalsController;
use App\Http\Controllers\Onboarding\ProfileController;
use App\Http\Controllers\Onboarding\RoleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Phase 1 — Sign Up / Login & Onboarding routes
|--------------------------------------------------------------------------
| require __DIR__.'/auth.php'; from routes/web.php
*/

// ---- Access gate (Dev/UAT only) ----
// Outside the `guest` group on purpose: this stands in front of the whole site, so it has to
// answer for signed-in visitors too. It is exempt from its own middleware via
// config('access_gate.except'), or the redirect would loop.
Route::get('/access', [AccessGateController::class, 'show'])->name('access.show');
Route::post('/access', [AccessGateController::class, 'store'])
    // A short code deserves a brute-force limit, low enough to be useless to a script and
    // high enough that a person mistyping twice is not locked out.
    ->middleware('throttle:10,1')
    ->name('access.store');

// ---- Guest ----
Route::middleware('guest')->group(function () {
    // Sign up (email-first)
    Route::get('/', [EmailSignupController::class, 'show'])->name('signup');
    Route::get('/signup', [EmailSignupController::class, 'show'])->name('signup.alias');
    Route::post('/signup/email', [EmailSignupController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('signup.email');

    // Verify 6-digit code
    Route::get('/verify', [VerifyCodeController::class, 'show'])->name('auth.verify.show');
    Route::post('/verify', [VerifyCodeController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('auth.verify');
    Route::post('/verify/resend', [VerifyCodeController::class, 'resend'])
        ->middleware('throttle:4,1')
        ->name('auth.verify.resend');

    // Sign in
    Route::get('/signin', [SignInController::class, 'show'])->name('signin');
    Route::post('/signin', [SignInController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('signin.store');

    // Social + SSO
    Route::get('/auth/{provider}/redirect', [SocialAuthController::class, 'redirect'])
        ->whereIn('provider', ['google', 'github'])->name('social.redirect');
    Route::get('/auth/{provider}/callback', [SocialAuthController::class, 'callback'])
        ->whereIn('provider', ['google', 'github'])->name('social.callback');
    Route::post('/sso/start', [SsoController::class, 'start'])
        ->middleware('throttle:10,1')->name('sso.start');
});

// ---- Authenticated (pre-workspace onboarding) ----
Route::middleware('auth')->group(function () {
    Route::get('/onboarding/profile', [ProfileController::class, 'show'])->name('onboarding.profile');
    Route::post('/onboarding/profile', [ProfileController::class, 'store'])->name('onboarding.profile.store');

    Route::post('/onboarding/avatar', [AvatarController::class, 'store'])
        ->middleware('throttle:20,1')->name('onboarding.avatar.store');
    Route::delete('/onboarding/avatar', [AvatarController::class, 'destroy'])->name('onboarding.avatar.destroy');

    Route::get('/onboarding/role', [RoleController::class, 'show'])->name('onboarding.role');
    Route::post('/onboarding/role', [RoleController::class, 'store'])->name('onboarding.role.store');
    Route::post('/onboarding/role/skip', [RoleController::class, 'skip'])->name('onboarding.role.skip');

    Route::get('/onboarding/goals', [GoalsController::class, 'show'])->name('onboarding.goals');
    Route::post('/onboarding/goals', [GoalsController::class, 'store'])->name('onboarding.goals.store');
    Route::post('/onboarding/goals/skip', [GoalsController::class, 'skip'])->name('onboarding.goals.skip');

    // Session idle-timeout clock (docs/features/session-timeout.md). `status` is deliberately
    // NOT activity — see EnforceIdleTimeout.
    Route::get('/session/status', [SessionController::class, 'status'])->name('session.status');
    Route::post('/session/extend', [SessionController::class, 'extend'])->name('session.extend');

    Route::post('/logout', LogoutController::class)->name('logout');
});

// ---- Session expiry landing ----
// In NEITHER the guest nor the auth group: this is where "Sign In Again" goes, and it has to
// work whether the session is already gone or only the browser thinks so. See
// SessionController@expired for why pointing the button at /signin directly does not.
Route::get('/session/expired', [SessionController::class, 'expired'])->name('session.expired');

// ---- Dev-only email viewer (guarded to local env in the controller) ----
Route::get('/emaillog', [EmailLogController::class, 'index'])->name('dev.emaillog');
Route::delete('/emaillog', [EmailLogController::class, 'destroyAll'])->name('dev.emaillog.destroy');
Route::get('/emaillog/{email}', [EmailLogController::class, 'show'])->name('dev.emaillog.show');
