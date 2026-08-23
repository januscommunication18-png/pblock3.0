<?php

namespace App\Http\Middleware;

use App\Models\BackofficeAuditLog;
use App\Services\Backoffice\BackofficeAudit;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idle and absolute timeouts on the Back Office session (docs/features/backoffice-auth.md, §9).
 *
 * A SEPARATE class from `EnforceIdleTimeout`, which watches the customer session. The two guard
 * different guards with different limits, and making one class serve both would mean one
 * timeout's change silently altering the other's.
 *
 * TWO clocks, because they answer different questions:
 *
 * - IDLE (30 minutes) — "has this person walked away?" Reset on every request.
 * - ABSOLUTE (12 hours) — "how long has this session been able to administer the platform?"
 *   Never reset. Without it, a session held open by a background tab that polls is a session
 *   that never ends.
 */
class BackofficeSessionTimeout
{
    private const IDLE_MINUTES = 30;

    private const ABSOLUTE_HOURS = 12;

    private const LAST_SEEN = 'backoffice.last_seen_at';

    private const STARTED = 'backoffice.started_at';

    public function __construct(private readonly BackofficeAudit $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('backoffice');

        if (! $guard->check() || ! $request->hasSession()) {
            return $next($request);
        }

        $now = Carbon::now()->getTimestamp();
        $lastSeen = (int) $request->session()->get(self::LAST_SEEN, $now);
        $started = (int) $request->session()->get(self::STARTED, $now);

        $idleFor = $now - $lastSeen;
        $aliveFor = $now - $started;

        $expired = $idleFor > self::IDLE_MINUTES * 60
            || $aliveFor > self::ABSOLUTE_HOURS * 3600;

        if ($expired) {
            $user = $guard->user();
            $reason = $idleFor > self::IDLE_MINUTES * 60 ? 'idle' : 'absolute';

            $this->audit->record(
                BackofficeAuditLog::LOGOUT,
                user: $user,
                meta: ['reason' => 'session_timeout', 'kind' => $reason],
            );

            $guard->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('backoffice.verify.show')
                ->with('status', 'Your Back Office session timed out. Please sign in again.');
        }

        $request->session()->put(self::LAST_SEEN, $now);
        // Written once and then left alone — this is the clock that must not be reset.
        $request->session()->put(self::STARTED, $started);

        return $next($request);
    }

    /** Called on a fresh login so the absolute clock starts now rather than at first request. */
    public static function begin(Request $request): void
    {
        $now = Carbon::now()->getTimestamp();
        $request->session()->put(self::LAST_SEEN, $now);
        $request->session()->put(self::STARTED, $now);
    }
}
