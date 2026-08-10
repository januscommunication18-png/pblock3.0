<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\EmailSignupRequest;
use App\Models\EmailVerificationCode;
use App\Services\AuthCodeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class EmailSignupController extends Controller
{
    public function __construct(private readonly AuthCodeService $codes) {}

    /** GET / (Sign up screen). */
    public function show(): View
    {
        return view('auth.signup');
    }

    /** POST /signup/email — email-first (AUTH-005/006/009): no password here. */
    public function store(EmailSignupRequest $request): RedirectResponse
    {
        $email = $request->validated('email');

        // Always issue a code; response is uniform whether or not the account exists (AC-7).
        $this->codes->issue($email, EmailVerificationCode::PURPOSE_SIGNUP);

        // Remember the acknowledgement of Terms/Privacy from the sign-up action (AUTH-008).
        $request->session()->put('pending_email', $email);
        $request->session()->put('pending_terms_accepted', true);

        return redirect()
            ->route('auth.verify.show')
            ->with('status', 'We sent a 6-digit code to '.$email.'.');
    }
}
