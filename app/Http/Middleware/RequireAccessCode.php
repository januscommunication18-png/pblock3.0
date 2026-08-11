<?php

namespace App\Http\Middleware;

use App\Services\AccessGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Holds every web request behind the access-code screen until a code has been entered.
 *
 * Registered on the whole `web` group rather than sprinkled over the auth routes: the point
 * is that a Dev or UAT site is not reachable, and a route added next month would otherwise
 * quietly be reachable. Opting a path out is a deliberate entry in `access_gate.except`.
 */
class RequireAccessCode
{
    public function __construct(private readonly AccessGate $gate) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->gate->active() || $this->gate->excludes($request) || $this->gate->passed($request)) {
            return $next($request);
        }

        // An API/fetch caller gets an answer it can act on rather than a login page's HTML.
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'This environment is protected. Enter the access code to continue.',
            ], 403);
        }

        // Remember where they were heading, so a shared deep link still lands on the right
        // page after the code — the gate should be a speed bump, not a redirect to the front
        // door. Only for GETs: replaying a POST after the gate would submit a form the user
        // no longer has in front of them.
        if ($request->isMethod('GET')) {
            $request->session()->put('url.intended', $request->fullUrl());
        }

        return redirect()->route('access.show');
    }
}
