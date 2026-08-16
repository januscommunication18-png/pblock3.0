<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\SessionTimeout;
use App\Support\EndsSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The endpoints the session guard talks to (docs/features/session-timeout.md).
 *
 * `status` and `extend` are behind `auth`, so an expired session hits EnforceIdleTimeout first
 * and gets the 401 the browser is already prepared to handle — neither ever has to answer for
 * a session that no longer exists.
 *
 * `expired` is behind neither `auth` nor `guest`, for the reason given on the method: it is the
 * one route that must work no matter which side of the timeout the caller is on.
 */
class SessionController extends Controller
{
    public function __construct(private readonly SessionTimeout $timeout) {}

    /**
     * GET /session/status — how long is left.
     *
     * Excluded from the activity stamp in EnforceIdleTimeout (SES-004). This is the one route
     * where being asked about the session must not count as using it.
     */
    public function status(Request $request): JsonResponse
    {
        return response()->json($this->timeout->payload($request));
    }

    /** POST /session/extend — "Stay Signed In", and the throttled activity ping behind it. */
    public function extend(Request $request): JsonResponse
    {
        $this->timeout->touch($request);

        return response()->json($this->timeout->payload($request));
    }

    /**
     * GET /session/expired — where "Sign In Again" goes.
     *
     * In NEITHER the `guest` nor the `auth` group, deliberately. The button exists for the one
     * moment the browser and the server may disagree: a slept laptop, a dropped ping or a
     * skewed clock can all leave a tab certain the session is over while the server still holds
     * it. Pointing the button straight at `/signin` fails in exactly that case — `signin` is a
     * guest route, so a still-authenticated visitor is bounced off it, and with no `dashboard`
     * or `home` route to bounce to they land back on `/`, which is a guest route as well.
     *
     * So this ends the session first and then sends them on. It is correct whether they were
     * signed in, already signed out, or somewhere in between.
     */
    public function expired(Request $request): RedirectResponse
    {
        // Always a redirect, never the 401 the middleware may return: this is a navigation,
        // and a browser following a link has nothing to do with a status code.
        EndsSession::teardown($request, $request->query('next'));

        return EndsSession::toSignIn();
    }
}
