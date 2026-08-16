<?php

namespace App\Services;

use App\Models\WorkspaceSettings;
use Illuminate\Http\Request;

/**
 * The idle-timeout clock (docs/features/session-timeout.md).
 *
 * All the arithmetic in one place, because three callers need to agree on it exactly: the
 * middleware that enforces expiry, the endpoint the browser polls for the remaining time, and
 * the settings screen that shows what the window currently is. When those disagree, a warning
 * fires after the session is already gone.
 *
 * State lives in the session under `last_activity_at` (a Unix timestamp). Not in the database:
 * this is written on nearly every request, and a table that every page-load updates is a
 * write amplifier for a value nothing outside the session ever reads.
 */
class SessionTimeout
{
    public const KEY = 'last_activity_at';

    /** Memoized for the request: the middleware, the status endpoint and the view all ask. */
    private ?int $resolved = null;

    /**
     * The idle window, in minutes, for the workspace this request belongs to.
     *
     * Falls back to the application default whenever a workspace value cannot be read — during
     * onboarding, before a workspace is chosen, or on a route without tenancy. A session must
     * always have SOME window; failing open here would mean no timeout at all.
     */
    public function minutes(): int
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $default = (int) config('settings.security.timeout_default', 30);

        $configured = rescue(fn () => $this->configuredMinutes(), null, report: false);

        $minutes = (int) ($configured ?: $default);

        // A value that is not on the whitelist can only have arrived by editing the row
        // directly. Treat it as unset rather than honouring it.
        return $this->resolved = in_array($minutes, $this->options(), true) ? $minutes : $default;
    }

    /**
     * Read the active workspace's configured window.
     *
     * Deliberately a query-builder read filtered by the signed-in user's OWN
     * `current_workspace_id`, rather than the tenant-scoped model. This runs in the `web`
     * middleware group — before `workspace.tenancy` has initialized a context — so the
     * BelongsToTenant scope would match nothing here and every workspace would silently get
     * the default. The explicit `where` IS the isolation (CLAUDE.md §7): the only workspace
     * reachable is the one the requesting user is already in.
     */
    private function configuredMinutes(): ?int
    {
        $workspaceId = auth()->user()?->current_workspace_id;

        if (! $workspaceId) {
            return null;
        }

        return WorkspaceSettings::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $workspaceId)
            ->value('session_timeout_minutes');
    }

    /** @return array<int, int> */
    public function options(): array
    {
        return array_map('intval', config('settings.security.timeout_options', [30]));
    }

    /**
     * How long before expiry the warning shows, in seconds.
     *
     * Clamped to half the window: the configured five minutes is sensible against thirty, and
     * absurd against six — a warning that is on screen for most of the session is just the
     * session (SES-005).
     */
    public function warningSeconds(): int
    {
        $window = $this->minutes() * 60;
        $warning = (int) config('settings.security.warning_minutes', 5) * 60;

        return (int) min($warning, floor($window / 2));
    }

    /** Seconds left before this session is considered idle. Zero once it has lapsed. */
    public function remainingSeconds(Request $request): int
    {
        $last = (int) $request->session()->get(self::KEY, 0);

        // No stamp yet — the session was created by this very request. Treat it as fresh
        // rather than as infinitely old, which would sign somebody out as they sign in.
        if ($last === 0) {
            return $this->minutes() * 60;
        }

        return (int) max(0, ($last + $this->minutes() * 60) - now()->getTimestamp());
    }

    public function hasExpired(Request $request): bool
    {
        return $request->session()->has(self::KEY) && $this->remainingSeconds($request) === 0;
    }

    /** Mark the session as active as of now. */
    public function touch(Request $request): void
    {
        $request->session()->put(self::KEY, now()->getTimestamp());
    }

    /**
     * What the browser needs to run its own countdown.
     *
     * The deadline is sent as a duration rather than an absolute time on purpose: a clock that
     * is wrong by an hour is common on a laptop, and would otherwise fire the warning on page
     * load or never.
     */
    /** @return array<string, int> */
    public function payload(Request $request): array
    {
        return [
            'remaining' => $this->remainingSeconds($request),
            'warn_at' => $this->warningSeconds(),
            'timeout_minutes' => $this->minutes(),
            'ping_seconds' => (int) config('settings.security.ping_seconds', 60),
            'draft_ttl_hours' => (int) config('settings.security.draft_ttl_hours', 24),
        ];
    }
}
