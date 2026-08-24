<?php

namespace App\Http\Middleware;

use App\Services\Backoffice\ClientAccess;
use App\Services\OnboardingRouter;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Initializes single-database tenancy to the authenticated user's current workspace for
 * the Settings routes (CLAUDE.md §7). Once initialized, every tenant-scoped model
 * (WorkspaceSettings, ProjectState, WorkspaceInvitation, …) is automatically confined to
 * that workspace by the BelongsToTenant global scope — controllers never filter by tenant
 * by hand. Users without a workspace are sent through first-workspace onboarding.
 *
 * Authorization (owner/admin) is enforced separately by the WorkspacePolicy in each
 * controller; this middleware only establishes the tenant context.
 */
class InitializeWorkspaceTenancy
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $workspace = $user?->currentWorkspace;

        if (! $workspace) {
            return redirect()->route('onboarding.workspace');
        }

        /*
         * A workspace the client may no longer enter is closed
         * (docs/features/backoffice-clients.md, §17, BC-D3).
         *
         * Here, and not only at sign-in. Sign-in can only ask the GLOBAL question — "is this
         * client disabled?" — because it does not yet know which workspace the person is heading
         * for. "Disable Tenant Access" is per-membership, so it can only be answered once a
         * workspace is in hand, and this is the chokepoint every tenant-scoped request already
         * passes through: no route can quietly miss it.
         */
        $access = app(ClientAccess::class);

        if (! $access->allowsTenant($user, $workspace)) {
            /*
             * ONE closed workspace is not a closed account.
             *
             * Somebody who belongs to five workspaces and has been removed from one must land in
             * another, not on the sign-in screen. Signing them out here would also be a LOOP
             * rather than a message: their `current_workspace_id` still names the closed
             * workspace, so signing back in walks them straight into this branch again.
             */
            $fallback = $user->workspaces()
                ->orderBy('name')
                ->get()
                ->first(fn ($candidate) => $access->allowsTenant($user, $candidate));

            if ($fallback) {
                $user->forceFill(['current_workspace_id' => $fallback->id])->save();

                // Only a GET is worth replaying: redirecting a POST to its own URL lands on a
                // 405, and the write it carried belonged to the workspace they just lost anyway.
                return $request->isMethod('GET')
                    ? redirect($request->fullUrl())
                    : redirect(app(OnboardingRouter::class)->landingFor($user->refresh()));
            }

            /*
             * Nowhere left to go — the client is globally disabled, or every membership is.
             *
             * Signed OUT rather than shown an error page: there is no workspace to render the
             * application around, so an error page would be a dead end they could not leave.
             */
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('signin')->withErrors(['email' => ClientAccess::BLOCKED_MESSAGE]);
        }

        tenancy()->initialize($workspace);

        return $next($request);
    }
}
