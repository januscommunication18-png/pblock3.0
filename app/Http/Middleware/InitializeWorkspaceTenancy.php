<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Backoffice\ClientAccess;
use App\Services\OnboardingRouter;
use App\Services\WorkspaceAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Initializes single-database tenancy to the authenticated user's current workspace
 * (CLAUDE.md §7), and — the part that makes it an access control rather than a convenience —
 * refuses to do so unless the user holds an ACTIVE membership of that workspace
 * (docs/features/workspace-access-control.md).
 *
 * ## Why the check belongs here
 *
 * This middleware is prepended before `SubstituteBindings` for every workspace-level route
 * group (bootstrap/app.php), so it is the single door every tenant-scoped request passes
 * through, and it runs before route-model binding resolves anything. Once tenancy is
 * initialized the `BelongsToTenant` global scope confines every tenant-owned model to that
 * workspace, which is what makes `/projects/15` in somebody else's workspace a 404 rather
 * than a leak — but only if the workspace it initialized to was theirs to begin with. That
 * "only if" is this class.
 *
 * The check cannot live in a policy: policies run after binding, on a model, and by then the
 * tenant scope has already been set to whatever `current_workspace_id` said.
 *
 * ## What it is guarding against
 *
 * `users.current_workspace_id` is a stored pointer, and nothing that revokes access clears it
 * — being removed from a workspace, being suspended from it, or having a membership deleted
 * in the Back Office all leave the pointer intact. Before this check, the next request from
 * that user initialized tenancy to the workspace they had just lost and carried on as before.
 *
 * Authorization *within* a workspace (owner/admin, project visibility) is still each policy's
 * job; this establishes only that the workspace may be entered at all.
 */
class InitializeWorkspaceTenancy
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('onboarding.workspace');
        }

        $access = app(WorkspaceAccess::class);
        $workspace = $user->currentWorkspace;

        if (! $workspace) {
            /*
             * No stored choice — a brand-new member, or somebody whose pointer was cleared when
             * their last membership changed. Adopt the first workspace they may open and carry
             * on: they are not being refused anything, so there is nothing to tell them.
             */
            $workspace = $access->workspacesFor($user)->first();

            if (! $workspace) {
                return redirect()->route('onboarding.workspace');
            }

            $user->forceFill(['current_workspace_id' => $workspace->id])->save();
        } elseif (! $access->allows($user, $workspace)) {
            return $this->refuse($request, $user, $access);
        }

        tenancy()->initialize($workspace);

        return $next($request);
    }

    /**
     * The current workspace is not (or is no longer) this user's to enter.
     *
     * Three outcomes, in the order they are worth trying:
     *   1. an API or fetch caller gets **403** — a redirect to a sign-in page is not an answer
     *      a JSON client can act on, and the requirement asks for the status code;
     *   2. somebody who belongs elsewhere is moved to a workspace they do hold, and told why —
     *      one closed workspace is not a closed account, and five out of six still work;
     *   3. nobody left with anywhere to go is sent to first-workspace onboarding, or signed
     *      out when the whole account is disabled (there is no workspace to render an error
     *      page around, so an error page would be a dead end they could not leave).
     */
    private function refuse(Request $request, User $user, WorkspaceAccess $access): Response
    {
        if ($request->expectsJson()) {
            abort(403, WorkspaceAccess::DENIED_MESSAGE);
        }

        $fallback = $access->workspacesFor($user)->first();

        if ($fallback) {
            $user->forceFill(['current_workspace_id' => $fallback->id])->save();

            /*
             * Sent to their landing page rather than replayed at the URL they asked for: that
             * URL named a resource in the workspace they just lost, and following it into a
             * different workspace would answer a 404 that reads like a bug rather than the
             * refusal it is.
             */
            return redirect(app(OnboardingRouter::class)->landingFor($user->refresh()))
                ->with('status', WorkspaceAccess::DENIED_MESSAGE);
        }

        if ($access->blockedGlobally($user)) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('signin')->withErrors(['email' => ClientAccess::BLOCKED_MESSAGE]);
        }

        return redirect()->route('onboarding.workspace')->with('status', WorkspaceAccess::DENIED_MESSAGE);
    }
}
