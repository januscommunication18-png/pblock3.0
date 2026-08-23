<?php

use App\Http\Controllers\Dev\LogViewerController;
use App\Http\Middleware\EnforceIdleTimeout;
use App\Http\Middleware\BackofficeSessionTimeout;
use App\Http\Middleware\EnsureBackofficeVerified;
use App\Http\Middleware\InitializeWorkspaceTenancy;
use App\Http\Middleware\InjectSessionGuard;
use App\Http\Middleware\RequireAccessCode;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // Stateless webhook routes, under /api — no session and no CSRF, because the callers
        // are other people's servers. See routes/api.php.
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        /*
         * Diagnostics, registered with NO middleware group at all.
         *
         * Deliberately outside `web`: that group starts a session, and sessions here are
         * database-backed — so a log viewer inside it would be unreachable exactly when the
         * database is down, which is when the log matters most. It authenticates itself with
         * a secret and opens no connection.
         */
        then: function () {
            Route::get('/errorlog.php', LogViewerController::class)->name('dev.errorlog');
            Route::get('/errorlog.php/{token}', LogViewerController::class);
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Initializes tenancy to the user's current workspace for settings routes
        // (single-database tenancy — activates the BelongsToTenant row scope).
        /*
         * Where a GUEST is sent lives in AppServiceProvider, not here.
         *
         * `Authenticate::redirectUsing()` there already owns this decision, and it runs during
         * boot — after this file — so a second `redirectGuestsTo()` set here would be silently
         * overridden. One rule, in the place that actually wins.
         */

        $middleware->alias([
            'workspace.tenancy' => InitializeWorkspaceTenancy::class,
            /*
             * The Back Office gate (docs/features/backoffice-auth.md, §4 and §9).
             *
             * Aliases rather than group-appends: unlike the customer middleware below, these
             * must apply to SOME routes and not others — `backoffice.verified` guards the login
             * screen but must not guard the verification screen that leads to it, or nobody
             * could ever reach either.
             */
            'backoffice.verified' => EnsureBackofficeVerified::class,
            'backoffice.timeout' => BackofficeSessionTimeout::class,
        ]);

        // Access gate (Dev/UAT): holds every web request behind the access-code screen until
        // a code has been entered. On the whole group rather than on the auth routes, so a
        // route added later cannot quietly be reachable. Inert in production and wherever
        // the module is switched off — see App\Services\AccessGate.
        $middleware->web(append: RequireAccessCode::class);

        // Idle-timeout enforcement + the guard markup that warns before it fires
        // (docs/features/session-timeout.md). On the whole web group, not on the authenticated
        // routes, so a route added later cannot quietly opt out of the timeout. Both are inert
        // for guests.
        $middleware->web(append: [
            EnforceIdleTimeout::class,
            InjectSessionGuard::class,
        ]);

        // Tenancy MUST initialize before route-model binding so that bindings of
        // tenant-scoped models (project states, labels, invitations, …) are confined to
        // the active workspace — a foreign-tenant id then 404s instead of leaking.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: InitializeWorkspaceTenancy::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Render JSON for API paths and for any request that explicitly asks for JSON
        // (the settings screens post via fetch with Accept: application/json and rely on
        // 422 validation payloads).
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
