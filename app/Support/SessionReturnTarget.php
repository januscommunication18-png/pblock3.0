<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Where to put somebody back after they sign in again (SES-008).
 *
 * A return target arrives from two places — the session (set by the middleware that expired
 * them) and a `?next=` parameter (set by the browser when a fetch came back 401, at which point
 * there is no session left to write to). Both are attacker-reachable, so both go through
 * `sanitize()`: an open redirect on a sign-in page is the classic phishing primitive, since the
 * URL a person is sent to right after entering credentials is the one they trust most.
 */
class SessionReturnTarget
{
    /*
     * NOT `url.intended`. RequireAccessCode writes that key for its own purpose, so on a Dev or
     * UAT site with the gate on, the gate overwrote the page somebody was reading with the
     * sign-in URL — which sanitize() then rejects as an auth route, silently dropping the
     * return target. Two features, two keys.
     */
    public const KEY = 'session.return_to';

    /**
     * Reduce a candidate to a safe same-origin path, or null.
     *
     * Only relative paths survive. Not "same host as us" — that check invites bypasses through
     * userinfo (`https://our.app@evil.com`) and backslash handling that differs between the
     * parser and the browser. A leading `//` is rejected for the same reason: browsers read it
     * as protocol-relative, i.e. another origin.
     */
    public static function sanitize(?string $candidate): ?string
    {
        $candidate = trim((string) $candidate);

        if ($candidate === '' || ! str_starts_with($candidate, '/') || str_starts_with($candidate, '//')) {
            return null;
        }

        // Control characters (including the newline and tab some parsers strip mid-URL) have
        // no business in a path and are how `/\evil.com` style tricks get smuggled in.
        if (preg_match('/[\x00-\x1F\x7F\\\\]/', $candidate)) {
            return null;
        }

        // Sending somebody back to a POST-only or auth route lands them on a 405 or a loop.
        foreach (['/signin', '/signup', '/verify', '/logout', '/access', '/session/'] as $blocked) {
            if (str_starts_with($candidate, $blocked)) {
                return null;
            }
        }

        return $candidate;
    }

    /** Remember where to return to, if it is somewhere worth returning to. */
    public static function remember(Request $request, ?string $candidate): void
    {
        if ($path = self::sanitize($candidate)) {
            $request->session()->put(self::KEY, $path);
        }
    }

    /** The remembered target, consumed. Null when there is nothing pending. */
    public static function pull(Request $request): ?string
    {
        return self::sanitize($request->session()->pull(self::KEY));
    }
}
