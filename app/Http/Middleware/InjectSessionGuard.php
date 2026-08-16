<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puts the session-guard markup on every authenticated page (docs/features/session-timeout.md).
 *
 * WHY A MIDDLEWARE, and not `@include` in a layout: this application has ~16 separate full-page
 * Blade templates rather than one shell — `settings/layout`, `projects/settings/layout`, and
 * every screen that composes `app-topbar` itself. Adding an include to each makes coverage a
 * rule somebody has to remember on the seventeenth page, and the failure is invisible: the page
 * looks fine, it just never warns anybody. Appending here means a page added tomorrow is
 * covered by code written today.
 *
 * Narrow by design — an authenticated HTML response with a `</body>` to append to. Streamed,
 * JSON, redirect, download and fragment responses are all left exactly as they were.
 */
class InjectSessionGuard
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $this->shouldInject($request, $response)) {
            return $response;
        }

        $html = $response->getContent();
        $marker = strripos($html, '</body>');

        if ($marker === false) {
            return $response;
        }

        $guard = view('partials.session-guard')->render();

        /*
         * Preserve `original` across setContent().
         *
         * Illuminate\Http\Response::setContent() reassigns `original` to whatever it is given —
         * so writing the rendered string back replaces the View object the response was built
         * from. Nothing in the browser notices, but `assertViewHas`, `assertViewIs` and
         * `viewData()` all read `original`, and every one of them starts reporting "the
         * response is not a view" on a page this middleware has touched. Appending a script tag
         * must not change what the response IS.
         */
        $original = $response->original;
        $response->setContent(substr($html, 0, $marker).$guard.substr($html, $marker));
        $response->original = $original;

        return $response;
    }

    private function shouldInject(Request $request, Response $response): bool
    {
        return Auth::check()
            && $request->isMethod('GET')
            && ! $this->isEmbedded($request)
            && ! $request->expectsJson()
            && $response->isSuccessful()
            && $response->headers->has('Content-Type')
            && str_contains((string) $response->headers->get('Content-Type'), 'text/html')
            && is_string($response->getContent());
    }

    /**
     * Is this document being loaded INSIDE another one?
     *
     * The Views panel opens work items in an `<iframe>` (public/assets/js/projects/views.js).
     * A guard injected there runs a second countdown in the same tab, and — much worse — its
     * "Sign In Again" sets the IFRAME's location, so the sign-in screen appears inside a detail
     * panel while the page around it sits there signed out and unchanged.
     *
     * One guard per tab, owned by the top-level document. `Sec-Fetch-Dest` is sent by every
     * browser this application supports; if it is missing the request is treated as top-level,
     * which fails towards HAVING the warning rather than silently losing it.
     */
    private function isEmbedded(Request $request): bool
    {
        return in_array(
            strtolower((string) $request->headers->get('Sec-Fetch-Dest')),
            ['iframe', 'frame', 'embed', 'object'],
            true,
        );
    }
}
