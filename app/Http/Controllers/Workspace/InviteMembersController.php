<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Http\Requests\Workspace\InviteMembersRequest;
use App\Services\WorkspaceInviter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * In-app teammate invitations for the current workspace (spec §6), reachable from the
 * welcome / get-started home. Distinct from the first-run onboarding invite step: this is
 * available any time and only to owners/admins of the active workspace.
 */
class InviteMembersController extends Controller
{
    public function __construct(private readonly WorkspaceInviter $inviter) {}

    /** GET /workspaces/invite */
    public function show(): View|RedirectResponse
    {
        $user = Auth::user();
        $workspace = $user->currentWorkspace;

        if (! $workspace) {
            return redirect()->route('onboarding.workspace');
        }
        abort_unless($user->can('invite', $workspace), 403);

        return view('workspace.invite', [
            'user' => $user,
            'workspace' => $workspace,
            'roles' => config('workspace.invite_roles'),
            'roleLabels' => config('workspace.roles'),
            'rows' => 3,
        ]);
    }

    /** POST /workspaces/invite */
    public function store(InviteMembersRequest $request): RedirectResponse
    {
        $user = Auth::user();
        $workspace = $user->currentWorkspace;

        if (! $workspace) {
            return redirect()->route('onboarding.workspace');
        }
        abort_unless($user->can('invite', $workspace), 403);

        $results = $this->inviter->invite($workspace, $user, $request->inviteRows());

        $sent = collect($results)->where('status', 'invited')->count();
        $message = $sent > 0
            ? "{$sent} ".str('invitation')->plural($sent).' sent.'
            : 'No new invitations were sent.';

        return redirect()->route('welcome')
            ->with('status', $message)
            ->with('invite_results', $results);
    }
}
