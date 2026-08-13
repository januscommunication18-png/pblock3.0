<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\UpdatePreferenceRequest;
use App\Models\Workspace;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * The account modal's Preference tab — Language & Time (Account §2).
 *
 * Reached from a personal menu but WORKSPACE-scoped, and owner/admin only. The first day of
 * the week and the weekend decide where a calendar breaks and which days a cycle counts, so
 * they belong to the organisation: two people in one workspace disagreeing about them would
 * put the same cycle on two different dates.
 *
 * `timezone` is the same column Settings → General edits. Deliberately the same, not a copy —
 * one workspace has one timezone, and the two screens are two doors into it.
 */
class PreferenceController extends Controller
{
    /** PATCH /account/preference */
    public function update(UpdatePreferenceRequest $request): JsonResponse
    {
        $workspace = $request->user()->currentWorkspace;

        $workspace->forceFill([
            'timezone' => $request->validated('timezone'),
            'language' => $request->validated('language'),
            'first_day_of_week' => (int) $request->validated('first_day_of_week'),
            // Sorted, so the stored order is the week's order rather than the order the user
            // happened to tick the boxes in — otherwise two identical weekends compare unequal.
            'weekend_days' => collect($request->validated('weekend_days'))->sort()->values()->all(),
        ])->save();

        return response()->json([
            'ok' => true,
            'preference' => self::payload($workspace->fresh()),
            'message' => 'Preferences updated.',
        ]);
    }

    /**
     * What the tab renders from, plus the option lists behind its four controls.
     *
     * @return array<string, mixed>
     */
    public static function payload(Workspace $workspace): array
    {
        $defaults = config('workspace.week_defaults');

        return [
            'timezone' => $workspace->timezone ?: 'UTC',
            'language' => $workspace->language ?: 'en',
            'first_day_of_week' => (int) ($workspace->first_day_of_week ?: $defaults['first_day']),
            'weekend_days' => array_map('intval', $workspace->weekend_days ?? $defaults['weekend']),

            'timezones' => self::timezoneOptions(),
            'languages' => self::languageOptions(),
            'days' => collect(config('workspace.week_days'))
                ->map(fn (string $label, int $value) => ['value' => (string) $value, 'label' => $label])
                ->values()->all(),
        ];
    }

    /**
     * Languages, with the ones that have no pack yet marked rather than hidden.
     *
     * Showing what is coming is more useful than an empty list — but the picker disables them
     * and the request refuses them, so the label is the only place "coming soon" exists.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function languageOptions(): array
    {
        return collect(config('workspace.languages', []))
            ->map(fn (array $l) => [
                'value' => $l['value'],
                'label' => ($l['available'] ?? false) ? $l['label'] : $l['label'].' — coming soon',
                'disabled' => ! ($l['available'] ?? false),
            ])
            ->values()->all();
    }

    /**
     * Every IANA zone as `(GMT+05:30) Asia/Kolkata`, sorted by current offset then name.
     *
     * The same shape and the same sort GeneralSettingsController produces, because it is the
     * same setting seen from another screen — a timezone that read one way in Settings and
     * another way here would look like two different values.
     *
     * @return array<int, array{value: string, label: string}>
     */
    private static function timezoneOptions(): array
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $zones = array_map(function (string $tz) use ($now) {
            $offset = (new DateTimeZone($tz))->getOffset($now);
            $sign = $offset < 0 ? '-' : '+';
            $abs = abs($offset);

            return [
                'value' => $tz,
                'label' => sprintf('(GMT%s%02d:%02d) %s', $sign, intdiv($abs, 3600), intdiv($abs % 3600, 60), str_replace('_', ' ', $tz)),
                'offset' => $offset,
            ];
        }, DateTimeZone::listIdentifiers());

        usort($zones, fn ($a, $b) => [$a['offset'], $a['value']] <=> [$b['offset'], $b['value']]);

        return array_map(fn (array $z) => ['value' => $z['value'], 'label' => $z['label']], $zones);
    }

    /** May the signed-in user see this tab at all? Owner/admin of the active workspace. */
    public static function allowed(): bool
    {
        $workspace = Auth::user()?->currentWorkspace;

        return $workspace !== null && Auth::user()->can('manageSettings', $workspace);
    }
}
