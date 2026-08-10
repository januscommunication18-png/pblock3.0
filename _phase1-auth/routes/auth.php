<?php

use App\Http\Controllers\Auth\EmailSignupController;
use App\Http\Controllers\Auth\LogoutController;
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

    // Phase 3 entry stub (until workspaces land).
    Route::view('/onboarding/done', 'onboarding.done')->name('onboarding.done');

    Route::post('/logout', LogoutController::class)->name('logout');
});

// ---- Dev-only email viewer (guarded to local env in the controller) ----
Route::get('/emaillog', [EmailLogController::class, 'index'])->name('dev.emaillog');
Route::get('/emaillog/{email}', [EmailLogController::class, 'show'])->name('dev.emaillog.show');
