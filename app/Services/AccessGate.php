<?php

namespace App\Services;

use Illuminate\Http\Request;

/**
 * The access gate module — the "secure page" that stands in front of Sign up / Sign in.
 *
 * Its purpose is narrow: keep a Dev or UAT deployment off the public internet without giving
 * every tester an account. It is NOT authentication and must never be mistaken for it — a
 * shared four-digit code protects against a passer-by and a search engine, nothing more.
 *
 * Everything that asks "is the gate on?" or "is this code right?" comes through here, so the
 * backoffice toggle — when that is built — replaces the body of `enabled()` and nothing else
 * has to move.
 */
class AccessGate
{
    /**
     * Is the gate switched on?
     *
     * Unset means "decide from the environment", so a fresh Dev or UAT deploy is private by
     * default. This is the method the backoffice will read a stored setting from.
     */
    public function enabled(): bool
    {
        $configured = config('access_gate.enabled');

        // A bare `ACCESS_GATE_ENABLED=` in an env file reaches here as an empty STRING, not
        // null — so testing for null alone read it as "explicitly off" and left a Dev site
        // wide open while the config said it was private. Anything blank means "not set".
        if ($configured === null || $configured === '') {
            return $this->allowedHere();
        }

        return filter_var($configured, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $this->allowedHere();
    }

    /**
     * May the gate run in this environment at all?
     *
     * An allow-list, checked independently of `enabled()`: the gate keeps NON-production
     * copies private, so a stray ACCESS_GATE_ENABLED=true in a production env file must not
     * be able to put the live site behind a code page.
     */
    public function allowedHere(): bool
    {
        return app()->environment(config('access_gate.environments', []));
    }

    /** Is the gate actually standing in front of this site right now? */
    public function active(): bool
    {
        return $this->allowedHere() && $this->enabled();
    }

    /** Has this visitor already entered a code? */
    public function passed(Request $request): bool
    {
        return $request->session()->get($this->key()) === $this->fingerprint();
    }

    /**
     * Is this one of the accepted codes?
     *
     * `hash_equals` rather than `===`: a timing side-channel on a four-digit code is not a
     * realistic attack, but comparing secrets in constant time is cheap and this is the one
     * place in the app that compares a shared secret.
     */
    public function accepts(?string $code): bool
    {
        $code = trim((string) $code);

        if ($code === '') {
            return false;
        }

        foreach ((array) config('access_gate.codes', []) as $accepted) {
            if (hash_equals((string) $accepted, $code)) {
                return true;
            }
        }

        return false;
    }

    /** Record the pass for this session. */
    public function grant(Request $request): void
    {
        $request->session()->put($this->key(), $this->fingerprint());
    }

    public function revoke(Request $request): void
    {
        $request->session()->forget($this->key());
    }

    /** Should this path be let through untouched (the gate screen itself, health checks)? */
    public function excludes(Request $request): bool
    {
        foreach ((array) config('access_gate.except', []) as $pattern) {
            if ($request->is($pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A fingerprint of the current code list, stored instead of a bare `true`.
     *
     * Withdrawing or rotating a code then revokes every pass already handed out — otherwise
     * removing a leaked code from the list would change nothing for whoever already used it,
     * which is the one thing you actually want when you rotate a code.
     */
    public function fingerprint(): string
    {
        return hash('sha256', implode('|', (array) config('access_gate.codes', [])));
    }

    private function key(): string
    {
        return (string) config('access_gate.session_key', 'access_gate.pass');
    }
}
