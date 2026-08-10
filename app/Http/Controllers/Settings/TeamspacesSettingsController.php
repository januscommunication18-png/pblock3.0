<?php

namespace App\Http\Controllers\Settings;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;

/**
 * Features > Teamspaces (spec §10). Enabling Teamspaces is a ONE-WAY action: it requires
 * confirmation, stamps `teamspaces_locked_at`, and can never be turned off afterwards — the
 * API rejects any disable request once locked (never UI-only).
 */
class TeamspacesSettingsController extends SettingsController
{
    /** GET /settings/teamspaces */
    public function show(): View
    {
        $this->guardManage();
        $settings = $this->settings();

        return $this->page('teamspaces', [
            'enabled' => $settings->teamspaces_enabled,
            'locked' => $settings->teamspacesLocked(),
            'endpoints' => ['toggle' => route('settings.teamspaces.toggle')],
        ]);
    }

    /** POST /settings/teamspaces/toggle */
    public function toggle(): JsonResponse
    {
        $this->guardManage();
        $settings = $this->settings();
        $enabled = request()->boolean('enabled');

        // One-way: once locked, disabling is rejected outright (spec §10).
        abort_if(! $enabled && $settings->teamspacesLocked(), 422, 'Teamspaces cannot be turned off once enabled.');

        if ($enabled && ! $settings->teamspaces_enabled) {
            $settings->forceFill([
                'teamspaces_enabled' => true,
                'teamspaces_locked_at' => now(),
            ])->save();
        }

        return response()->json([
            'ok' => true,
            'enabled' => $settings->teamspaces_enabled,
            'locked' => $settings->teamspacesLocked(),
        ]);
    }
}
