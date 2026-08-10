<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
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

        tenancy()->initialize($workspace);

        return $next($request);
    }
}
