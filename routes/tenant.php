<?php

use App\Http\Controllers\Tenant\PortalController;
use App\Http\Middleware\IdentifyTenantByHost;
use App\Services\TenantSubdomain;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant host routes (docs/features/workspace-subdomain.md, P72)
|--------------------------------------------------------------------------
| Served from a tenant's OWN hostname — `acme.projectblock.app` — rather than from the shared
| application host. One subdomain per tenant with the products separated by route, which is the
| requirement's recommendation: the tenant publishes one address, and Help Center and Client Hub
| cannot collide over who owns `acme`.
|
| REQUIRED FIRST in web.php, and the reason is worth stating: a route with no domain constraint
| matches ANY host, so every central route already matches `acme.projectblock.app` too. Laravel
| takes the first route whose method, path and host all match — so registered after `auth.php`,
| a tenant `/help` would lose to anything central that happened to claim the same path, and
| would do so silently.
|
| Only these two paths are claimed. Everything else on a tenant host still falls through to the
| central application, which is the conservative choice: a customer following an old link to
| `/login` gets the login screen rather than a 404 from a route group that grabbed everything.
*/

Route::domain('{tenantSubdomain}.'.TenantSubdomain::root())
    ->middleware('web')
    ->group(function () {
        /*
         * `{tenantSubdomain}` is a route parameter only because `Route::domain()` needs the
         * pattern to be dynamic. It is NOT how the tenant is resolved — the middleware reads the
         * Host itself, so the same rule applies whether a request arrives here, at a route added
         * later, or through a console command.
         */
        Route::get('/help', [PortalController::class, 'help'])
            ->middleware(IdentifyTenantByHost::class.':helpdesk')
            ->name('tenant.help');

        Route::get('/client', [PortalController::class, 'client'])
            ->middleware(IdentifyTenantByHost::class.':clienthub')
            ->name('tenant.client');
    });
