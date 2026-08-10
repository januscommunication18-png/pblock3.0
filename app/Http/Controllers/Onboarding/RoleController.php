<?php

namespace App\Http\Controllers\Onboarding;

use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\RoleRequest;
use App\Models\OnboardingProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class RoleController extends Controller
{
    /** GET /onboarding/role (single-select, skippable — §7). */
    public function show(): View
    {
        return view('onboarding.role', [
            'user'  => Auth::user(),
            'roles' => config('onboarding.roles'),
        ]);
    }

    public function store(RoleRequest $request): RedirectResponse
    {
        $this->save(Auth::user(), $request->validated('role'));

        return redirect()->route('onboarding.goals');
    }

    /** POST /onboarding/role/skip */
    public function skip(): RedirectResponse
    {
        $this->save(Auth::user(), null);

        return redirect()->route('onboarding.goals');
    }

    private function save($user, ?string $role): void
    {
        OnboardingProfile::updateOrCreate(
            ['user_id' => $user->id],
            ['role_selection' => $role, 'current_step' => OnboardingProfile::STEP_GOALS],
        );
    }
}
