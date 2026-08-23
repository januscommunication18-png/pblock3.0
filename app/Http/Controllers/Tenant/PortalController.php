<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The customer-facing pages served from a tenant's own host
 * (docs/features/workspace-subdomain.md, P72).
 *
 * `acme.projectblock.app/help` and `/client`. One host per tenant, the products separated by
 * ROUTE underneath it — the requirement's own recommendation, and the reason a tenant gets one
 * domain to publish rather than two that can disagree about who owns `acme`.
 *
 * ## What this deliberately is NOT
 *
 * It is not a customer portal. Nothing here lists tickets, accepts a request or authenticates a
 * customer — none of that was specified, and inventing it would be building a product out of a
 * routing requirement. What it proves is that the host resolved to the right tenant and that the
 * product is switched on, which is the piece that was missing.
 *
 * The workspace arrives on the request from `IdentifyTenantByHost`, which has already 404'd
 * anything that does not resolve — so there is no null branch here, because there is no way to
 * reach these methods without one.
 */
class PortalController extends Controller
{
    /** GET https://{subdomain}.{root}/help */
    public function help(Request $request): View
    {
        return view('tenant.portal', [
            'workspace' => $this->workspace($request),
            'product' => 'Help Center',
            'blurb' => 'Support for customers of this workspace.',
        ]);
    }

    /** GET https://{subdomain}.{root}/client */
    public function client(Request $request): View
    {
        return view('tenant.portal', [
            'workspace' => $this->workspace($request),
            'product' => 'Client Hub',
            'blurb' => 'Shared workspace for clients of this organisation.',
        ]);
    }

    private function workspace(Request $request): Workspace
    {
        return $request->attributes->get('tenant_workspace');
    }
}
