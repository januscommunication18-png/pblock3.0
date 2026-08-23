<?php

namespace App\Http\Controllers\Backoffice;

use App\Http\Controllers\Controller;
use App\Http\Middleware\BackofficeSessionTimeout;
use App\Models\BackofficeAuditLog;
use App\Models\BackofficeUser;
use App\Services\Backoffice\BackofficeAudit;
use App\Services\Backoffice\BackofficeVerification;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Step 3 — the password (docs/features/backoffice-auth.md, §5).
 *
 * Reached only through `EnsureBackofficeVerified`, so by the time either method runs the visitor
 * has proved they hold an authorized mailbox.
 */
class LoginController extends Controller
{
    public function __construct(
        private readonly BackofficeVerification $verification,
        private readonly BackofficeAudit $audit,
    ) {}

    /** GET /backoffice/login */
    public function show(): View|RedirectResponse
    {
        if (Auth::guard('backoffice')->check()) {
            return redirect()->route('backoffice.dashboard');
        }

        return view('backoffice.login', [
            // Read-only on the form AND authoritative on the POST — the field is a reminder of
            // which account is being signed into, not an input.
            'email' => $this->verification->verifiedEmail(),
        ]);
    }

    /**
     * POST /backoffice/login
     *
     * The email is taken from the VERIFICATION SESSION and the request's copy is ignored
     * entirely (BO-D4). Accepting it would let somebody verify as one address and sign in as
     * another, which would make every step before this decorative.
     */
    public function store(Request $request): RedirectResponse
    {
        $email = $this->verification->verifiedEmail();

        if ($email === null) {
            return redirect()->route('backoffice.verify.show');
        }

        $data = $request->validate([
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
        ]);

        $user = BackofficeUser::query()->where('email', $email)->first();

        /*
         * `canSignIn()` covers "disabled since verifying" and "has never set a password".
         *
         * Checked before `attempt()` rather than trusting it: `attempt()` would refuse an empty
         * hash anyway, but the audit trail should say WHY, and "no password set" is a different
         * operational fact from "wrong password".
         */
        if ($user === null || ! $user->canSignIn()) {
            $this->audit->failure(BackofficeAuditLog::LOGIN_FAILED, $email, [
                'reason' => $user === null ? 'missing_user' : ($user->is_active ? 'no_password' : 'inactive'),
            ]);

            return back()->withErrors([
                'password' => 'We could not sign you in. Check your password, or reset it below.',
            ]);
        }

        $remember = (bool) ($data['remember'] ?? false);

        if (! Auth::guard('backoffice')->attempt(['email' => $email, 'password' => $data['password']], $remember)) {
            $this->audit->failure(BackofficeAuditLog::LOGIN_FAILED, $email, ['reason' => 'bad_password']);

            return back()->withErrors([
                'password' => 'We could not sign you in. Check your password, or reset it below.',
            ]);
        }

        /*
         * Regenerate, then start the clocks.
         *
         * Regeneration is what stops a session id planted before login becoming an authenticated
         * one. It also wipes the verification state, which is correct: it has done its job, and
         * leaving it behind would let a signed-out browser walk back into the login screen.
         */
        $request->session()->regenerate();
        $this->verification->forget();
        BackofficeSessionTimeout::begin($request);

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        $this->audit->record(BackofficeAuditLog::LOGIN_SUCCESS, $email, $user);

        return redirect()->intended(route('backoffice.dashboard'));
    }

    /** POST /backoffice/logout */
    public function destroy(Request $request): RedirectResponse
    {
        $user = Auth::guard('backoffice')->user();

        $this->audit->record(BackofficeAuditLog::LOGOUT, user: $user);

        Auth::guard('backoffice')->logout();

        // Invalidate AND rotate the CSRF token: the next visitor on this browser starts from
        // nothing, which is what §9's "session invalidation after logout" is protecting.
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('backoffice.verify.show')
            ->with('status', 'You have been signed out of the Back Office.');
    }
}
