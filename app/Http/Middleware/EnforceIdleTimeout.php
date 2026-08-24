<?php

namespace App\Http\Middleware;

use App\Services\SessionTimeout;
use App\Support\EndsSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs somebody out after the configured period of inactivity
 * (docs/features/session-timeout.md).
 *
 * Runs on the whole `web` group rather than on the authenticated routes, so a route added
 * later is covered by default instead of by remembering. It is inert for guests.
 *
 * ORDER MATTERS, and not in the way it first looks. The expiry check reads the stamp, then the
 * stamp is refreshed, then the request runs. Checking first is what stops every request being
 * its own proof of activity.
 *
 * Stamping BEFORE the response is built — rather than after, which was the first version — is
 * what keeps the browser's countdown honest. InjectSessionGuard renders `PB_SESSION` during
 * `$next()`, so a stamp written afterwards means the page is told the time remaining under the
 * PREVIOUS stamp while the server has already reset the clock. Idle 29 minutes, click a link,
 * and the new page announces 60 seconds while the server holds a full 30 minutes — the tab then
 * declares the session expired against a session that is perfectly alive.
 */
class EnforceIdleTimeout
{
    public function __construct(private readonly SessionTimeout $timeout) {}

    public function handle(Request $request, Closure $next): Response
    {
        /*
         * The Back Office runs its own clocks (BackofficeSessionTimeout) and its own front door.
         *
         * Without this, a person signed into BOTH apps in one browser who let the CUSTOMER
         * session go idle was torn down mid-Back-Office by the customer's rules: the shared
         * session is invalidated — which signs the `backoffice` guard out as collateral — and
         * they land on `/signin`, a screen that grants nothing in the Back Office. A Back Office
         * timeout must end at the Back Office front door, so these requests belong to the
         * middleware that knows that.
         */
        if ($request->is('backoffice', 'backoffice/*')) {
            return $next($request);
        }

        if (! Auth::check() || ! $request->hasSession()) {
            return $next($request);
        }

        if ($this->timeout->hasExpired($request)) {
            EndsSession::teardown($request, EndsSession::currentPath($request));

            return $request->expectsJson() ? EndsSession::expiredJson() : EndsSession::toSignIn();
        }

        // The status endpoint reports how long is left; it must not BE activity, or an open
        // tab polling it would keep itself alive forever (SES-004). `extend` is the opposite
        // — that one is a person clicking "Stay Signed In" — and stamps itself.
        if (! $request->routeIs('session.status')) {
            $this->timeout->touch($request);
        }

        return $next($request);
    }
}
