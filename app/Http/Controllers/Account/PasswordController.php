<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\UpdatePasswordRequest;
use App\Models\UserPasswordCredential;
use App\Notifications\PasswordChanged;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * The account modal's Change password tab (Account §4).
 *
 * The password lives in `user_password_credentials`, not on `users` (D-A2) — a row exists only
 * once someone has set one — so this writes there, which is also where SignInController checks
 * it. Writing `users.password` instead would appear to work and change nothing about signing in.
 */
class PasswordController extends Controller
{
    /** PATCH /account/password */
    public function update(UpdatePasswordRequest $request): JsonResponse
    {
        $user = $request->user();
        $credential = $user->passwordCredential;

        // Checked here rather than with the `current_password` rule, because that rule reads
        // `users.password`, which this app does not use.
        if ($credential && ! Hash::check((string) $request->validated('current_password'), $credential->password_hash)) {
            throw ValidationException::withMessages([
                'current_password' => 'That is not your current password.',
            ]);
        }

        $hash = Hash::make($request->validated('password'));

        if ($credential) {
            $credential->forceFill(['password_hash' => $hash, 'password_set_at' => now()])->save();
        } else {
            // First password for an account that signed in with a code or an identity provider.
            UserPasswordCredential::create([
                'user_id' => $user->id,
                'password_hash' => $hash,
                'password_set_at' => now(),
            ]);
        }

        // Sent BEFORE the session is torn down, so the notification is queued while the request
        // still has its user. Queued itself, so a slow mail server does not hold up the sign-out.
        $user->notify(new PasswordChanged($request->ip()));

        // §4.2: every session ends, this one included. A password change that left the current
        // browser signed in would leave a stolen session alive precisely when someone is trying
        // to close one.
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'ok' => true,
            'message' => 'Password changed. You have been signed out — sign in again with your new password.',
            'redirect' => route('signin'),
        ]);
    }
}
