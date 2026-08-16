<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Ending a session because it went idle, in one place (docs/features/session-timeout.md).
 *
 * Shared by the middleware that DETECTS the timeout and the endpoint the expiry dialog's
 * "Sign In Again" button leads to. Those two have to agree exactly — the button exists
 * precisely for the case where the browser thinks the session is over and the server does not —
 * and two copies of "log out, invalidate, regenerate, remember where they were" is how they
 * stop agreeing.
 *
 * Tearing down and answering are separate calls on purpose. The middleware may have to answer a
 * fetch with 401; the endpoint is always a navigation and must always redirect. Folding the two
 * together meant deciding for the caller, and getting it wrong for one of them.
 */
class EndsSession
{
    /**
     * Log out, throw the session away, and remember where to come back to.
     *
     * The return target is written AFTER invalidating, because invalidating throws the old
     * session away — writing it first is the classic way this silently stops working.
     */
    public static function teardown(Request $request, ?string $returnTo = null): void
    {
        if (Auth::check()) {
            Auth::logout();
        }

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            SessionReturnTarget::remember($request, $returnTo);
        }
    }

    /**
     * The answer a fetch gets. Never a bare status line — the browser turns this into the
     * expiry dialog, and `code` is what it matches on, since a 401 can equally mean "you were
     * never signed in".
     */
    public static function expiredJson(): JsonResponse
    {
        return new JsonResponse([
            'message' => 'Your session has expired. Sign in again to continue.',
            'code' => 'session_expired',
            'redirect' => route('signin'),
        ], 401);
    }

    public static function toSignIn(): RedirectResponse
    {
        return redirect()->route('signin')->with('session_expired', true);
    }

    /** The path-and-query of the current request, as a return target. */
    public static function currentPath(Request $request): string
    {
        return '/'.ltrim($request->path(), '/').
            ($request->getQueryString() ? '?'.$request->getQueryString() : '');
    }
}
