<?php

namespace App\Http\Middleware;

use App\Services\Backoffice\ClientAccess;
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
        $workspace = $request->user()?->currentWorkspace;

        if (! $workspace) {
            return redirect()->route('onboarding.workspace');
        }

        /*
         * A workspace whose CLIENT is disabled or pending deletion is closed
         * (docs/features/backoffice-clients.md, §17, BC-D3).
         *
         * Here, and not only at sign-in. The sign-in check refuses somebody who has nowhere left
         * to go, but a person can belong to two clients — one live, one disabled — and signing
         * in through the live one must not hand them the disabled one's workspace. This is the
         * chokepoint every tenant-scoped request already passes through, so there is no route
         * that can quietly miss it.
         *
         * The user is signed OUT rather than shown an error page: their session names the closed
         * workspace as current, so leaving them signed in would loop them back here on every
         * request with no way to pick a different one.
         */
        if (! app(ClientAccess::class)->allowsWorkspace($workspace)) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('signin')->withErrors(['email' => ClientAccess::BLOCKED_MESSAGE]);
        }

        tenancy()->initialize($workspace);

        return $next($request);
    }
}
