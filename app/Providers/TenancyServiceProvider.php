<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Stancl\Tenancy\Events;
use Stancl\Tenancy\Listeners;
use Stancl\Tenancy\Middleware;

/**
 * Tenancy wiring for pblock3.0.
 *
 * This project uses SINGLE-DATABASE tenancy (CLAUDE.md §7): every tenant (Workspace)
 * shares the one application database and rows are isolated by the BelongsToTenant
 * global scope. Because there are no per-tenant databases, the default job pipeline
 * that creates/migrates/deletes a tenant database (from the stancl/tenancy stub) has
 * been removed. Initializing tenancy only sets the current tenant and boots the
 * (currently empty) bootstrapper list configured in config/tenancy.php.
 */
class TenancyServiceProvider extends ServiceProvider
{
    public function events(): array
    {
        return [
            // Initialize / revert tenancy context. These are the only listeners we need
            // for single-database tenancy — no database provisioning jobs.
            Events\TenancyInitialized::class => [
                Listeners\BootstrapTenancy::class,
            ],
            Events\TenancyEnded::class => [
                Listeners\RevertToCentralContext::class,
            ],
        ];
    }

    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->bootEvents();
        $this->makeTenancyMiddlewareHighestPriority();
    }

    protected function bootEvents(): void
    {
        foreach ($this->events() as $event => $listeners) {
            foreach ($listeners as $listener) {
                Event::listen($event, $listener);
            }
        }
    }

    protected function makeTenancyMiddlewareHighestPriority(): void
    {
        $tenancyMiddleware = [
            Middleware\PreventAccessFromCentralDomains::class,
            Middleware\InitializeTenancyByDomain::class,
            Middleware\InitializeTenancyBySubdomain::class,
            Middleware\InitializeTenancyByDomainOrSubdomain::class,
            Middleware\InitializeTenancyByPath::class,
            Middleware\InitializeTenancyByRequestData::class,
        ];

        foreach (array_reverse($tenancyMiddleware) as $middleware) {
            $this->app[Kernel::class]->prependToMiddlewarePriority($middleware);
        }
    }
}
