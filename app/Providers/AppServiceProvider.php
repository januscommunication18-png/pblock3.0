<?php

namespace App\Providers;

use App\Events\WorkspaceInvitationAccepted;
use App\Services\HelpCenter\HelpCenterNavigation;
use App\Services\OnboardingRouter;
use App\Services\WikiNavigation;
use App\Services\WorkspaceSwitcher;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
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
        /*
         * Where an already-signed-in visitor goes when they open a guest page.
         *
         * Laravel's default walks to `/` when no `dashboard` or `home` route exists — and in
         * this application `/` IS a guest route (`signup`). So the default sent a signed-in
         * person from /signin to / to / … until the browser gave up, and any guest URL opened
         * while signed in was an infinite redirect rather than a page.
         *
         * OnboardingRouter already knows where somebody belongs, including mid-onboarding, so
         * this asks it rather than naming a route that is only right for finished accounts.
         */
        RedirectIfAuthenticated::redirectUsing(
            fn (Request $request) => app(OnboardingRouter::class)->landingFor($request->user()),
        );

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

        // The Wiki sidebar, for the same reason: it rides along with the shared sidebar on every
        // Wiki screen, and its collections list must be permission-filtered in ONE place rather
        // than in each of the four controllers that happen to render it.
        View::composer('partials.wiki-nav', function ($view) {
            $user = Auth::user();
            $nav = app(WikiNavigation::class);

            $view->with([
                'wikiSections' => $nav->sectionsFor(request()),
                'wikiCollections' => $user ? $nav->collectionsFor($user, request()) : [],
            ]);
        });

        /*
         * The Help Center sidebar (docs/features/help-center.md §13, §15), for the same reason
         * as the Wiki's: it rides along with every Help Center screen, so its Spaces tree is
         * assembled in ONE place rather than in each of the five controllers that render it.
         */
        View::composer('partials.help-center-nav', function ($view) {
            /*
             * Just the Spaces tree now.
             *
             * This used to also build the "Spaces +" dialog's payload — the member list, the
             * type suggestions, an endpoint. That dialog is gone (the "+" links to the six-step
             * setup flow), and with it a member query that ran on EVERY Help Center page to
             * populate a dropdown almost nobody opened.
             */
            $view->with('helpCenterSpaces', app(HelpCenterNavigation::class)->spaces(request()));
        });

        /*
         * WorkspaceInvitationAccepted currently has no listeners.
         *
         * The Help Desk's was the only one, and it moved to legacy/help-desk with the rest of
         * that module. The EVENT stays here and is still fired: it is a workspace fact — an
         * invitation was accepted — and the next thing that needs to react to one should not
         * have to reintroduce it.
         */
    }
}
