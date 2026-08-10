<?php

use App\Http\Middleware\InitializeWorkspaceTenancy;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Initializes tenancy to the user's current workspace for settings routes
        // (single-database tenancy — activates the BelongsToTenant row scope).
        $middleware->alias([
            'workspace.tenancy' => InitializeWorkspaceTenancy::class,
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
