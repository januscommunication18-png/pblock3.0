<?php

namespace App\Http\Controllers\Backoffice;

use App\Http\Controllers\Controller;
use App\Models\BackofficeAuditLog;
use App\Models\BackofficeUser;
use App\Services\Backoffice\BackofficeAudit;
use App\Services\Backoffice\BackofficeVerification;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * Forgot / reset password for the Back Office (docs/features/backoffice-auth.md, §6).
 *
 * Uses Laravel's `backoffice_users` broker, which resolves through the Back Office provider — so
 * a token minted here can only ever be redeemed against a `backoffice_users` row, whatever
 * address is typed.
 *
 * The rule that shapes the end of this flow: a completed reset does NOT sign anybody in. It
 * returns them to `/backoffice` so they go through email verification again. A reset link is
 * proof of mailbox control, which this product has already decided is not sufficient on its own
 * to administer the platform.
 */
class PasswordResetController extends Controller
{
    public function __construct(
        private readonly BackofficeAudit $audit,
        private readonly BackofficeVerification $verification,
    ) {}

    /** GET /backoffice/forgot-password */
    public function request(): View
    {
        return view('backoffice.forgot-password', [
            // Prefilled from the verification session when there is one — somebody who got to
            // the login screen and could not remember their password already typed this once.
            'email' => $this->verification->verifiedEmail(),
        ]);
    }

    /**
     * POST /backoffice/forgot-password
     *
     * One message whatever happens, for the same reason step 1 has one (BO-D7): the response
     * must not say whether the address is a Back Office account.
     */
    public function email(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email', 'max:255']]);
        $email = mb_strtolower(trim($data['email']));

        $this->audit->record(BackofficeAuditLog::PASSWORD_RESET_REQUESTED, $email);

        $user = BackofficeUser::query()->where('email', $email)->first();

        if ($user !== null && $user->is_active) {
            // The broker's own throttle (60s) applies; its return value is deliberately ignored
            // so a throttled request and a sent one look identical from outside.
            Password::broker('backoffice_users')->sendResetLink(['email' => $email]);
        } else {
            Hash::make('backoffice-timing-equaliser');
        }

        return back()->with('status',
            'If that address belongs to a Back Office account, a reset link is on its way.');
    }

    /** GET /backoffice/reset-password/{token} */
    public function reset(Request $request, string $token): View
    {
        return view('backoffice.reset-password', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    /**
     * POST /backoffice/reset-password
     *
     * On success the user is NOT authenticated — §6 is explicit. They land back at `/backoffice`
     * and start the two-step gate over.
     */
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(12)->letters()->mixedCase()->numbers()->symbols()],
        ]);

        $status = Password::broker('backoffice_users')->reset(
            $data,
            function (BackofficeUser $user, string $password) {
                $user->forceFill([
                    'password_hash' => Hash::make($password),
                    'password_set_at' => now(),
                    // Any "remember me" cookie minted before the reset stops working. A password
                    // change is often a response to a compromise, and leaving long-lived tokens
                    // alive would leave the intruder signed in.
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));

                $this->audit->record(BackofficeAuditLog::PASSWORD_CHANGED, $user->email, $user);
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            $this->audit->failure(BackofficeAuditLog::PASSWORD_CHANGED, $data['email'], [
                'reason' => (string) $status,
            ]);

            return back()->withErrors(['email' => __($status)]);
        }

        /*
         * Back to the beginning, deliberately (§6).
         *
         * Any verification session is dropped too: whoever completed the reset may not be the
         * person who verified twenty minutes ago, and inheriting that state would hand them a
         * step they never took.
         */
        $this->verification->forget();

        return redirect()->route('backoffice.verify.show')
            ->with('status', 'Your password has been updated. Please sign in.');
    }
}
