<?php

namespace App\Services\Backoffice;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Session;

/**
 * The short-lived verification session between the code screen and the login screen
 * (docs/features/backoffice-auth.md, §4).
 *
 * SERVER-SIDE session state (BO-D3). Not a signed cookie and not a token in the URL: a URL token
 * survives being pasted into a chat window, and a cookie value is the browser's own claim about
 * what it is allowed to do. This is the server remembering, for twenty minutes, that somebody
 * proved they hold a mailbox.
 *
 * Everything about the pending state lives under one key so that `forget()` cannot leave half of
 * it behind — a stale `verified_email` with no expiry is an open door that looks closed.
 */
class BackofficeVerification
{
    private const KEY = 'backoffice.verification';

    /**
     * How long a completed verification is good for.
     *
     * 20 minutes: §4 asks for 15–30, and this is long enough to find a password manager without
     * being long enough to matter if somebody walks away from the login screen.
     */
    public const TTL_MINUTES = 20;

    /** The address awaiting a code — set at step 1, before anything has been proved. */
    public function startPending(string $email): void
    {
        Session::put(self::KEY, [
            'email' => mb_strtolower(trim($email)),
            'verified' => false,
            'expires_at' => null,
        ]);
    }

    public function pendingEmail(): ?string
    {
        $state = (array) Session::get(self::KEY, []);

        return $state['email'] ?? null;
    }

    /**
     * The code was accepted. The SAME address it was issued to is confirmed — never one the
     * request supplied, which is the whole of BO-D4.
     */
    public function confirm(string $email): void
    {
        Session::put(self::KEY, [
            'email' => mb_strtolower(trim($email)),
            'verified' => true,
            'expires_at' => Carbon::now()->addMinutes(self::TTL_MINUTES)->getTimestamp(),
        ]);

        /*
         * Regenerated the moment the state becomes meaningful.
         *
         * Without this, a session id an attacker planted before verification would now be a
         * verified one. Laravel regenerates on login; this is the earlier privilege step and
         * needs the same treatment.
         */
        Session::regenerate();
    }

    /** The verified address, or null when there is no live verification. */
    public function verifiedEmail(): ?string
    {
        $state = (array) Session::get(self::KEY, []);

        if (($state['verified'] ?? false) !== true) {
            return null;
        }

        $expires = (int) ($state['expires_at'] ?? 0);

        if ($expires === 0 || Carbon::createFromTimestamp($expires)->isPast()) {
            // Expired state is REMOVED rather than left to be re-read. A row that says
            // "verified: true, expired" is one bad condition away from being honoured.
            $this->forget();

            return null;
        }

        return $state['email'] ?? null;
    }

    public function isVerified(): bool
    {
        return $this->verifiedEmail() !== null;
    }

    public function forget(): void
    {
        Session::forget(self::KEY);
    }
}
