<?php

namespace App\Http\Controllers\Settings;

use App\Http\Requests\Settings\UpdateSecuritySettingsRequest;
use App\Services\SessionTimeout;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;

/**
 * Administration > Security (docs/features/session-timeout.md).
 *
 * One setting today — how long a session may sit idle. Owner/admin only, like every other
 * section: a member who could widen the timeout could widen it for everybody.
 */
class SecuritySettingsController extends SettingsController
{
    /** GET /settings/security */
    public function show(SessionTimeout $timeout): View
    {
        $this->guardManage();

        return $this->page('security', [
            'timeoutMinutes' => $timeout->minutes(),
            'options' => array_map(
                fn (int $m) => ['value' => $m, 'label' => $this->label($m)],
                $timeout->options(),
            ),
            'warningMinutes' => (int) round($timeout->warningSeconds() / 60),
            'endpoints' => ['update' => route('settings.security.update')],
        ]);
    }

    /** PATCH /settings/security */
    public function update(UpdateSecuritySettingsRequest $request, SessionTimeout $timeout): JsonResponse
    {
        $this->guardManage();

        $this->settings()
            ->forceFill(['session_timeout_minutes' => (int) $request->validated('session_timeout_minutes')])
            ->save();

        // Applying it to the person who just changed it, immediately: leaving them on the old
        // window until their next sign-in is the kind of gap that gets reported as "it didn't
        // save". The service memoizes per request, so it is re-resolved fresh here.
        $timeout->touch($request);

        return response()->json([
            'ok' => true,
            'timeoutMinutes' => (int) $request->validated('session_timeout_minutes'),
        ]);
    }

    private function label(int $minutes): string
    {
        if ($minutes < 60) {
            return "{$minutes} minutes";
        }

        $hours = intdiv($minutes, 60);

        return $hours === 1 ? '1 hour' : "{$hours} hours";
    }
}
