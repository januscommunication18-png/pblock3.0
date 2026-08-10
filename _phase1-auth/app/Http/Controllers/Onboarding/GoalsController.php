<?php

namespace App\Http\Controllers\Onboarding;

use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\GoalsRequest;
use App\Models\OnboardingProfile;
use App\Services\OnboardingRouter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class GoalsController extends Controller
{
    public function __construct(private readonly OnboardingRouter $router) {}

    /** GET /onboarding/goals (multi-select, skippable — §8). */
    public function show(): View
    {
        return view('onboarding.goals', [
            'user'  => Auth::user(),
            'goals' => config('onboarding.goals'),
        ]);
    }

    public function store(GoalsRequest $request): RedirectResponse
    {
        $this->complete(Auth::user(), $request->validated('goals'));

        return redirect()->route($this->router->postOnboardingRoute(Auth::user()));
    }

    /** POST /onboarding/goals/skip */
    public function skip(): RedirectResponse
    {
        $this->complete(Auth::user(), null);

        return redirect()->route($this->router->postOnboardingRoute(Auth::user()));
    }

    private function complete($user, ?array $goals): void
    {
        OnboardingProfile::updateOrCreate(
            ['user_id' => $user->id],
            [
                'goals'        => $goals,
                'current_step' => OnboardingProfile::STEP_COMPLETED,
                'completed_at' => now(),
            ],
        );
    }
}
