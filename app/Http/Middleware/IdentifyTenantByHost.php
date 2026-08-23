<?php

namespace App\Http\Middleware;

use App\Models\Workspace;
use App\Services\TenantSubdomain;
use App\Services\WorkspaceApps;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolve a request to the tenant that owns its hostname
 * (docs/features/workspace-subdomain.md, P72).
 *
 * `acme.projectblock.app/help` → the workspace whose `subdomain` is `acme`, with single-database
 * tenancy initialised so every `BelongsToTenant` query below is confined to it.
 *
 * ## Ours rather than stancl's
 *
 * `InitializeTenancyBySubdomain` ships with the package and is already prioritised in
 * `TenancyServiceProvider`. It resolves through a `domains` table this project does not have —
 * our identifier lives on `tenants.subdomain` — so adopting it would mean creating and
 * synchronising a second table that says the same thing as a column we already maintain.
 *
 * ## An unknown subdomain is a 404, and so is a known one without the product
 *
 * Never "this workspace exists but has not enabled Client Hub". That sentence confirms a tenant
 * to somebody who guessed their name, and the guessing is free — the whole point of a public
 * hostname is that anybody can try one. The two cases are indistinguishable from outside, which
 * is the intention.
 *
 * ## Tenancy is ended on the way out
 *
 * `tenancy()->initialize()` sets a process-global current tenant. Under a long-running worker
 * (Octane, a queue worker that serves requests) a request that ended without clearing it would
 * leave the next one scoped to somebody else's workspace. `php artisan serve` forks per request
 * and would never show this; that is exactly why it is written down rather than left to luck.
 */
class IdentifyTenantByHost
{
    public function __construct(private readonly WorkspaceApps $apps) {}

    /**
     * @param  string|null  $requires  an app key the workspace must have enabled, e.g. `helpdesk`
     */
    public function handle(Request $request, Closure $next, ?string $requires = null): Response
    {
        $label = TenantSubdomain::fromHost($request->getHost());

        // Not one of our tenant hosts at all. Should be unreachable — the route group is
        // domain-constrained — but a middleware that assumed its own route is a middleware that
        // breaks the day somebody reuses it.
        abort_if($label === null, 404);

        /*
         * `withoutGlobalScopes()` is required rather than tidy: no tenancy is active yet, and
         * `Workspace` is the tenant model itself, so anything the scope did here would be
         * circular. This is the one lookup that is legitimately unscoped.
         */
        $workspace = Workspace::query()
            ->withoutGlobalScopes()
            ->where('subdomain', $label)
            ->first();

        abort_if($workspace === null, 404);

        // The product must actually be switched on for this workspace. Same 404 as an unknown
        // name, deliberately — see the note above.
        // `WorkspaceApps` owns the map from an app key to the settings flag that records it —
        // asking it here rather than reading a column keeps that in the one place its own
        // docblock says it lives.
        abort_if($requires !== null && ! $this->apps->isEnabled($workspace, $requires), 404);

        tenancy()->initialize($workspace);

        $request->attributes->set('tenant_workspace', $workspace);

        try {
            return $next($request);
        } finally {
            // Always, including when the response was an exception: see the note above.
            tenancy()->end();
        }
    }
}
