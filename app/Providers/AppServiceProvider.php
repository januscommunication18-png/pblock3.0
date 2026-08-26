<?php

namespace App\Providers;

use App\Events\WorkspaceInvitationAccepted;
use App\Listeners\LinkHelpCenterSpaceMemberships;
use App\Models\BackofficeUser;
use App\Services\HelpCenter\HelpCenterNavigation;
use App\Services\OnboardingRouter;
use App\Services\WikiNavigation;
use App\Services\WorkspaceSwitcher;
use App\Support\SessionReturnTarget;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
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

        /*
         * And the mirror of it: where a GUEST goes when they open a page that needs an account.
         *
         * Laravel's `Authenticate` middleware sends them to `route('login')`. This application
         * has no route by that name — sign-in is `signin` — so every authenticated URL opened
         * by a signed-out visitor raised "Route [login] not defined" and rendered a 500 instead
         * of a sign-in page. A link mailed to somebody is exactly the case that hits this:
         * the whole point of it is that it is opened later, from a mail client, by a browser
         * that may well have no session.
         *
         * `?next=` rather than the intended-URL session key, because that is the parameter
         * SignInController already reads, and SessionReturnTarget already refuses anything that
         * is not a safe same-origin path — an open redirect on a sign-in page is the one place
         * it does the most damage. Only GETs are remembered: sending somebody back to a
         * POST-only URL after signing in lands them on a 405.
         */
        Authenticate::redirectUsing(function (Request $request) {
            /*
             * The Back Office has its own front door (docs/features/backoffice-auth.md, §9).
             *
             * Checked FIRST, because `/backoffice/dashboard` opened by a signed-out visitor was
             * otherwise sent to the customer sign-in with `?next=/backoffice/clients` — and
             * signing in there as a customer grants nothing in the Back Office, so it was a
             * redirect to a screen that could not possibly help. No `next`: the Back Office
             * entry point is a two-step gate that ends at its own dashboard, and a return target
             * threaded through it would be a fourth thing for that flow to carry.
             */
            if ($request->is('backoffice', 'backoffice/*')) {
                return route('backoffice.verify.show');
            }

            $next = $request->isMethod('GET')
                ? SessionReturnTarget::sanitize($request->getRequestUri())
                : null;

            return $next ? route('signin', ['next' => $next]) : route('signin');
        });

        // The workspace switcher rides along with the shared topbar on every screen, so its
        // data is bound to the partial rather than passed by each controller — otherwise
        // every page that shows the topbar would have to remember to supply it.
        View::composer('partials.workspace-switcher', function ($view) {
            $user = Auth::user();

            // Grouped into My Workspaces / Invited Workspaces
            // (docs/features/tenant-workspace-ownership.md §12).
            $view->with(
                'switcherGroups',
                $user ? app(WorkspaceSwitcher::class)->groupsFor($user) : [],
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
        /*
         * Managing Back Office users is the Super Admin's alone (docs/features/backoffice-auth.md, §8).
         *
         * A Gate rather than a role string in the route file, so the answer lives beside every
         * other authorization answer in this application and a second caller cannot invent a
         * slightly different version of it.
         *
         * Typed against BackofficeUser: the customer `User` model has no `canManageAdmins()`,
         * so a signed-in customer reaching this gate is refused by PHP rather than by a check
         * somebody remembered to write.
         */
        Gate::define('manage-backoffice-admins', function ($user) {
            return $user instanceof BackofficeUser && $user->canManageAdmins();
        });

        View::composer('partials.help-center-nav', function ($view) {
            /*
             * The Spaces tree, and the queues above it (P21).
             *
             * This used to also build the "Spaces +" dialog's payload — the member list, the
             * type suggestions, an endpoint. That dialog is gone (the "+" links to the six-step
             * setup flow), and with it a member query that ran on EVERY Help Center page to
             * populate a dropdown almost nobody opened.
             */
            $nav = app(HelpCenterNavigation::class);

            $view->with([
                'helpCenterSpaces' => $nav->spaces(request()),
                /*
                 * The top-level queues and their counts (P21).
                 *
                 * Here rather than in each controller for the reason the tree is: the bar rides
                 * along with every Help Center screen, and a screen that forgot to supply it
                 * would silently render a navigation with a hole in it. One counts query.
                 */
                'helpCenterQueue' => $nav->queue(request()),
                // Company & Customer (P75 §1) — null unless a Space runs the feature, which is
                // what keeps the item off the bar rather than a check in the partial.
                'helpCenterCompanyCustomer' => $nav->companyCustomer(request()),
            ]);
        });

        /*
         * A pending Help Center Space membership becomes a real one when its invitation is
         * accepted (P10).
         *
         * A listener rather than a call inside WorkspaceInvitationAccepter: accepting an
         * invitation is a WORKSPACE fact, and the accepter should not have to know that the
         * Help Center — or anything else added later — cares about it.
         */
        Event::listen(WorkspaceInvitationAccepted::class, LinkHelpCenterSpaceMemberships::class);
    }
}
