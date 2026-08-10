<?php

namespace App\Http\Controllers\Onboarding;

use App\Http\Controllers\Controller;
use App\Http\Requests\Workspace\StoreOnboardingWorkspaceRequest;
use App\Services\OnboardingRouter;
use App\Services\WorkspaceCreator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * First-workspace onboarding — the "Create your workspace" step (spec §3).
 * No view selector here; the workspace is created as Agile by default (WS-VIEW-004).
 */
class WorkspaceController extends Controller
{
    public function __construct(
        private readonly WorkspaceCreator $creator,
        private readonly OnboardingRouter $router,
    ) {}

    /** GET /onboarding/workspace */
    public function show(): View|RedirectResponse
    {
        $user = Auth::user();

        // Already has a workspace? Move forward (invite or home) rather than re-creating.
        if ($user->workspaces()->exists()) {
            return redirect()->route($this->router->postOnboardingRoute($user));
        }

        return view('onboarding.workspace', [
            'user' => $user,
            'teamSizes' => config('workspace.team_sizes'),
        ]);
    }

    /** POST /onboarding/workspace */
    public function store(StoreOnboardingWorkspaceRequest $request): RedirectResponse
    {
        $this->creator->create(Auth::user(), $request->workspaceData());

        return redirect()->route('onboarding.invite');
    }
}
