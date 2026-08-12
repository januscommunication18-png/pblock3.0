<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SignInRequest;
use App\Models\EmailVerificationCode;
use App\Models\User;
use App\Services\AuthCodeService;
use App\Services\OnboardingRouter;
use Illuminate\Http\RedirectResponse;
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
    public function show(): View
    {
        return view('auth.signin');
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

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->route($this->router->destinationFor($user));
    }
}
