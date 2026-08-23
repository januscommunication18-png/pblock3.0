<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SignInRequest;
use App\Models\EmailVerificationCode;
use App\Models\User;
use App\Services\AuthCodeService;
use App\Services\OnboardingRouter;
use App\Support\SessionReturnTarget;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use App\Services\Backoffice\ClientAccess;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class SignInController extends Controller
{
    public function __construct(
        private readonly AuthCodeService $codes,
        private readonly OnboardingRouter $router,
    ) {}

    /** GET /signin */
    public function show(Request $request): View
    {
        // `?next=` is how the browser hands back the page somebody was on when a fetch came
        // back 401 — at that point the session is already gone, so it cannot be passed in it.
        // Sanitized on the way in and again on the way out (SES-008).
        SessionReturnTarget::remember($request, $request->query('next'));

        return view('auth.signin', [
            'sessionExpired' => (bool) $request->session()->get('session_expired'),
        ]);
    }

    /**
     * POST /signin
     * - With a password: verify against user_password_credentials (LOGIN-002).
     * - Without a password: send a 6-digit login code (LOGIN-003).
     * Responses never reveal whether the account exists (LOGIN-004 / AC-7).
     */
    public function store(SignInRequest $request): RedirectResponse
    {
        $email = $request->validated('email');
        $password = $request->validated('password');

        $user = User::where('email', $email)->first();

        // Passwordless path: issue a login code and go to the verify screen.
        if (! $password) {
            $this->codes->issue($email, EmailVerificationCode::PURPOSE_LOGIN);
            $request->session()->put('pending_email', $email);
            $request->session()->put('login_purpose', EmailVerificationCode::PURPOSE_LOGIN);

            return redirect()
                ->route('auth.verify.show')
                ->with('status', 'If that email exists, we sent it a 6-digit code.');
        }

        // Password path.
        $credential = $user?->passwordCredential;
        if (! $user || ! $credential || ! Hash::check($password, $credential->password_hash)) {
            return back()
                ->withInput(['email' => $email])
                ->withErrors(['email' => 'Those credentials do not match our records.']);
        }

        /*
         * A disabled or pending-deletion CLIENT cannot sign in (backoffice-clients.md, §17).
         *
         * Checked at the sign-in path rather than only in the Back Office UI (BC-D3): a status
         * nothing enforces is a switch that does nothing. Placed after the credentials are
         * verified on purpose — telling somebody their company is disabled before they have
         * proved who they are would answer a question they have not earned.
         */
        if (! app(ClientAccess::class)->allows($user)) {
            return back()
                ->withInput(['email' => $email])
                ->withErrors(['email' => ClientAccess::BLOCKED_MESSAGE]);
        }

        // Read BEFORE regenerating: regeneration is what carries the session forward, and
        // pulling afterwards works today only because Laravel migrates the data — reading
        // first makes the intent explicit rather than incidental.
        $returnTo = SessionReturnTarget::pull($request);

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->to($this->router->landingFor($user, $returnTo));
    }
}
