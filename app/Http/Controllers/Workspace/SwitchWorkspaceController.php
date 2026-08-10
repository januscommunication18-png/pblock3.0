<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Switches the user's active workspace (spec §7 — workspace switcher). Only workspaces the
 * user is an active member of may be selected; the choice is persisted as
 * users.current_workspace_id (WS-009) so it sticks across sessions.
 */
class SwitchWorkspaceController extends Controller
{
    /** POST /workspaces/{workspace}/switch */
    public function __invoke(Workspace $workspace): RedirectResponse
    {
        $user = Auth::user();

        // Authorize against active membership — never leak or switch into a foreign workspace.
        abort_unless($user->can('view', $workspace), 403);

        $user->forceFill(['current_workspace_id' => $workspace->id])->save();

        return redirect()->route('welcome')
            ->with('status', "Switched to \"{$workspace->name}\".");
    }
}
