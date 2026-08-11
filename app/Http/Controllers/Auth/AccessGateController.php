<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AccessGate;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The access-code screen in front of Sign up / Sign in.
 *
 * Deliberately thin: the gate is a shared secret for keeping a non-production site private,
 * not an identity, so there is no user, no rate-limit state of its own beyond the route's
 * throttle, and nothing to remember but "this session got in".
 */
class AccessGateController extends Controller
{
    public function __construct(private readonly AccessGate $gate) {}

    /** GET /access */
    public function show(Request $request): View|RedirectResponse
    {
        // Nothing to protect, or already through — never leave someone staring at a code box
        // that no longer does anything.
        if (! $this->gate->active() || $this->gate->passed($request)) {
            return redirect()->intended(route('signup'));
        }

        return view('auth.access', ['environment' => app()->environment()]);
    }

    /** POST /access */
    public function store(Request $request): RedirectResponse
    {
        if (! $this->gate->active()) {
            return redirect()->intended(route('signup'));
        }

        $request->validate(
            ['code' => ['required', 'string', 'max:64']],
            ['code.required' => 'Enter the access code to continue.'],
        );

        if (! $this->gate->accepts($request->input('code'))) {
            // One message for both "empty" and "wrong": the response should not help someone
            // work out how close they are.
            throw ValidationException::withMessages([
                'code' => 'That code is not valid for this environment.',
            ]);
        }

        // A fresh session id after passing the gate, so a session fixed before it cannot be
        // used to ride in — the same reason logging in regenerates.
        $request->session()->regenerate();
        $this->gate->grant($request);

        return redirect()->intended(route('signup'));
    }
}
