<?php

/*
|--------------------------------------------------------------------------
| Access gate — the "secure page" in front of Sign up / Sign in
|--------------------------------------------------------------------------
| A code page that stands in front of the whole site so a Dev or UAT copy is
| not open to the public. Enter one of the codes, continue, and the normal
| Sign up / Sign in screens appear.
|
| This is a MODULE: today it is switched on and off here, and the backoffice
| will switch it later. Everything reads it through App\Services\AccessGate,
| so when the backoffice lands only that class changes.
*/

return [
    /**
     * The module switch. `null` means "decide from the environment" — on in the
     * environments listed below, off everywhere else — which is what makes a fresh Dev or
     * UAT deploy private without anyone remembering to set anything.
     *
     * Set ACCESS_GATE_ENABLED=false to open a Dev site up, or =true to force it on.
     */
    'enabled' => env('ACCESS_GATE_ENABLED'),

    /**
     * Where the gate may run AT ALL.
     *
     * Production is deliberately absent and the guard is an allow-list, not a deny-list: the
     * gate exists to keep non-production copies private, and a stray ACCESS_GATE_ENABLED=true
     * in a production env file must never be able to put the live site behind a code page.
     */
    'environments' => array_filter(array_map('trim', explode(
        ',', (string) env('ACCESS_GATE_ENVIRONMENTS', 'local,development,dev,uat,staging'),
    ))),

    /**
     * Accepted codes. Several rather than one so a code can be handed to a particular group
     * (QA, the client, a demo) and later withdrawn without disturbing anyone else.
     *
     * Changing this list revokes every pass already granted — see AccessGate::fingerprint().
     */
    'codes' => array_filter(array_map('trim', explode(
        ',', (string) env('ACCESS_GATE_CODES', '1000,2000,3000,4000,5000'),
    ))),

    /** Session key holding the granted pass. */
    'session_key' => 'access_gate.pass',

    /**
     * Paths the gate never applies to, as `Request::is()` patterns.
     *
     * The gate's own screen has to be reachable or the redirect loops, and the health check
     * has to answer for the load balancer — a deploy probe is not a member of the public.
     */
    'except' => [
        'access',
        'access/*',
        'up',
    ],
];
