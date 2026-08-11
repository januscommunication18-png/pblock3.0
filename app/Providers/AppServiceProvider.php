<?php

namespace App\Providers;

use App\Services\WorkspaceSwitcher;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        require_once __DIR__.'/../helpers.php';
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The workspace switcher rides along with the shared topbar on every screen, so its
        // data is bound to the partial rather than passed by each controller — otherwise
        // every page that shows the topbar would have to remember to supply it.
        View::composer('partials.workspace-switcher', function ($view) {
            $user = Auth::user();

            $view->with(
                'switcherWorkspaces',
                $user ? app(WorkspaceSwitcher::class)->workspacesFor($user) : [],
            );
        });
    }
}
