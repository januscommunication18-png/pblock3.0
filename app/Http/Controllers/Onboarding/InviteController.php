<?php

namespace App\Http\Controllers\Onboarding;

use App\Http\Controllers\Controller;
use App\Http\Requests\Workspace\StoreInvitesRequest;
use App\Models\OnboardingProfile;
use App\Services\WorkspaceInviter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * First-workspace onboarding — "Invite your teammates" step (spec §6).
 * Invitations are optional; the user may send them or choose "I'll do it later".
 */
class InviteController extends Controller
{
    public function __construct(private readonly WorkspaceInviter $inviter) {}

    /** GET /onboarding/invite */
    public function show(): View|RedirectResponse
    {
        $user = Auth::user();

        $workspace = $user->currentWorkspace;
        if (! $workspace) {
            return redirect()->route('onboarding.workspace');
        }

        // Invite step already finished/skipped — go home.
        if (optional($user->onboardingProfile)->hasCompletedWorkspaceSetup()) {
            return redirect()->route('welcome');
        }

        return view('onboarding.invite', [
            'user' => $user,
            'workspace' => $workspace,
            'roles' => config('workspace.invite_roles'),
            'roleLabels' => config('workspace.roles'),
            'rows' => 3, // POC renders three empty rows
        ]);
    }

    /** POST /onboarding/invite */
    public function store(StoreInvitesRequest $request): RedirectResponse
    {
        $user = Auth::user();
        $workspace = $user->currentWorkspace;

        if (! $workspace) {
            return redirect()->route('onboarding.workspace');
        }

        $results = $this->inviter->invite($workspace, $user, $request->inviteRows());
        $this->markWorkspaceSetupComplete($user);

        $sent = collect($results)->where('status', 'invited')->count();
        $message = $sent > 0
            ? "You're all set — {$sent} ".str('invitation')->plural($sent).' sent.'
            : "You're all set — welcome to Project Block!";

        return redirect()->route('welcome')
            ->with('status', $message)
            ->with('invite_results', $results);
    }

    /** POST /onboarding/invite/skip — "I'll do it later" (spec §6). */
    public function skip(): RedirectResponse
    {
        $this->markWorkspaceSetupComplete(Auth::user());

        return redirect()->route('welcome')
            ->with('status', "You're all set — welcome to Project Block!");
    }

    private function markWorkspaceSetupComplete($user): void
    {
        OnboardingProfile::updateOrCreate(
            ['user_id' => $user->id],
            ['workspace_setup_completed_at' => now()],
        );
    }
}
