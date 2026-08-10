<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\VerifyCodeRequest;
use App\Models\EmailVerificationCode;
use App\Models\OnboardingProfile;
use App\Models\User;
use App\Models\UserIdentity;
use App\Services\AuthCodeService;
use App\Services\OnboardingRouter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class VerifyCodeController extends Controller
{
    public function __construct(
        private readonly AuthCodeService $codes,
        private readonly OnboardingRouter $router,
    ) {}

    /** GET /verify */
    public function show(): View|RedirectResponse
    {
        $email = session('pending_email');
        if (! $email) {
            return redirect()->route('signup');
        }

        return view('auth.verify', ['email' => $email]);
    }

    /** POST /verify — creates or resumes the account, then logs in. */
    public function store(VerifyCodeRequest $request): RedirectResponse
    {
        $email = $request->validated('email');
        $code  = $request->validated('code');
        $purpose = session('login_purpose', EmailVerificationCode::PURPOSE_SIGNUP);

        if (! $this->codes->verify($email, $code, $purpose)) {
            return back()->withErrors(['code' => 'That code is invalid or has expired.']);
        }

        $user = DB::transaction(function () use ($email, $request) {
            /** @var User $user */
            $user = User::firstOrCreate(
                ['email' => $email],
                [
                    'status'            => 'active',
                    'email_verified_at' => now(),
                    'terms_accepted_at' => $request->session()->pull('pending_terms_accepted') ? now() : null,
                ],
            );

            if (! $user->email_verified_at) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            // Ensure an email identity exists (AUTH-002/007: one identity per verified email).
            UserIdentity::firstOrCreate([
                'provider'         => UserIdentity::PROVIDER_EMAIL,
                'provider_subject' => $email,
            ], ['user_id' => $user->id]);

            // Ensure an onboarding row exists so resume works (ONB-006).
            OnboardingProfile::firstOrCreate(
                ['user_id' => $user->id],
                ['current_step' => OnboardingProfile::STEP_PROFILE],
            );

            return $user;
        });

        $request->session()->forget(['pending_email', 'login_purpose']);
        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->route($this->router->destinationFor($user));
    }

    /** POST /verify/resend */
    public function resend(): RedirectResponse
    {
        $email = session('pending_email');
        if ($email) {
            $purpose = session('login_purpose', EmailVerificationCode::PURPOSE_SIGNUP);
            $this->codes->issue($email, $purpose);
        }

        return back()->with('status', 'A new code is on its way.');
    }
}
