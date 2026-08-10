<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\OnboardingProfile;
use App\Models\User;
use App\Models\UserIdentity;
use App\Services\OnboardingRouter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Facades\Socialite;

/**
 * Google / GitHub OAuth (AUTH-002/003). Requires laravel/socialite and
 * provider config in config/services.php. Tokens are never persisted.
 */
class SocialAuthController extends Controller
{
    private const PROVIDERS = [
        'google' => UserIdentity::PROVIDER_GOOGLE,
        'github' => UserIdentity::PROVIDER_GITHUB,
    ];

    public function __construct(private readonly OnboardingRouter $router) {}

    public function redirect(string $provider): RedirectResponse
    {
        abort_unless(isset(self::PROVIDERS[$provider]), 404);

        return Socialite::driver($provider)->redirect();
    }

    public function callback(string $provider): RedirectResponse
    {
        abort_unless(isset(self::PROVIDERS[$provider]), 404);

        try {
            $oauth = Socialite::driver($provider)->user();
        } catch (\Throwable $e) {
            return redirect()->route('signup')
                ->withErrors(['email' => 'We could not complete '.ucfirst($provider).' sign-in. Please try again.']);
        }

        $email = strtolower(trim((string) $oauth->getEmail()));
        if ($email === '') {
            return redirect()->route('signup')
                ->withErrors(['email' => ucfirst($provider).' did not share an email address.']);
        }

        $user = DB::transaction(function () use ($provider, $oauth, $email) {
            /** @var User $user */
            $user = User::firstOrCreate(
                ['email' => $email],
                [
                    'full_name'         => $oauth->getName(),
                    'avatar_url'        => $oauth->getAvatar(),
                    'status'            => 'active',
                    'email_verified_at' => now(), // provider-verified email
                    'terms_accepted_at' => now(),
                ],
            );

            UserIdentity::firstOrCreate([
                'provider'         => self::PROVIDERS[$provider],
                'provider_subject' => (string) $oauth->getId(),
            ], ['user_id' => $user->id]);

            OnboardingProfile::firstOrCreate(
                ['user_id' => $user->id],
                ['current_step' => OnboardingProfile::STEP_PROFILE],
            );

            return $user;
        });

        Auth::login($user, remember: true);
        request()->session()->regenerate();

        return redirect()->route($this->router->destinationFor($user));
    }
}
