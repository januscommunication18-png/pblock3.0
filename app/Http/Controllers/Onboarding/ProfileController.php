<?php

namespace App\Http\Controllers\Onboarding;

use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\ProfileRequest;
use App\Models\OnboardingProfile;
use App\Models\UserPasswordCredential;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /** GET /onboarding/profile (step 1 of the flow). */
    public function show(): View
    {
        return view('onboarding.profile', ['user' => Auth::user()]);
    }

    /** POST /onboarding/profile (ONB-001/003/004/005). */
    public function store(ProfileRequest $request): RedirectResponse
    {
        $user = Auth::user();

        $user->fill([
            'full_name' => $request->validated('full_name'),
            'marketing_opt_in' => (bool) $request->validated('marketing_opt_in'),
        ])->save();

        // Optional password -> user_password_credentials (spec D-A2).
        if ($request->boolean('set_password') && $request->filled('password')) {
            UserPasswordCredential::updateOrCreate(
                ['user_id' => $user->id],
                ['password_hash' => Hash::make($request->validated('password')), 'password_set_at' => now()],
            );
        }

        $this->advance($user, OnboardingProfile::STEP_ROLE);

        return redirect()->route('onboarding.role');
    }

    private function advance($user, string $step): void
    {
        OnboardingProfile::updateOrCreate(
            ['user_id' => $user->id],
            ['current_step' => $step],
        );
    }
}
