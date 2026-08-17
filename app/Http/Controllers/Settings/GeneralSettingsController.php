<?php

namespace App\Http\Controllers\Settings;

use App\Http\Requests\Settings\UpdateWorkspaceGeneralRequest;
use App\Http\Requests\Settings\UploadWorkspaceLogoRequest;
use App\Services\WorkspaceApps;
use App\Services\WorkspaceDeleter;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * Administration > General (spec §4). Workspace identity + danger-zone deletion.
 */
class GeneralSettingsController extends SettingsController
{
    /** GET /settings/general */
    public function show(WorkspaceApps $apps): View
    {
        $this->guardManage();
        $w = $this->workspace();

        return $this->page('general', [
            'workspace' => [
                'name' => $w->name,
                'slug' => $w->slug,
                'company_size' => $w->company_size,
                'timezone' => $w->timezone,
                'logo_url' => $w->logo_url,
                'initial' => $w->initial(),
            ],
            'urlPrefix' => 'app.projectblock.so/',
            'teamSizes' => config('workspace.team_sizes'),
            'timezones' => $this->timezoneOptions(),
            'canDelete' => Auth::user()->can('delete', $w),
            // What this workspace has subscribed to (docs/features/wiki.md). Shown here and
            // editable alongside the rest of the workspace's identity, because "what can this
            // workspace do" is the same kind of question as what it is called.
            'apps' => $apps->all($w),
            'endpoints' => [
                'update' => route('settings.general.update'),
                'logo' => route('settings.general.logo'),
                'delete' => route('settings.general.destroy'),
            ],
        ]);
    }

    /** PATCH /settings/general */
    public function update(UpdateWorkspaceGeneralRequest $request, WorkspaceApps $apps): JsonResponse
    {
        $this->guardManage();
        $w = $this->workspace();

        $w->forceFill([
            'name' => $request->validated('name'),
            'company_size' => $request->validated('company_size'),
            'slug' => $request->validated('slug'),
            'timezone' => $request->validated('timezone'),
        ])->save();

        // Only when the form actually sent them: a request that says nothing about apps must
        // not read as "turn everything off".
        if ($request->has('apps')) {
            $apps->sync($w, (array) $request->validated('apps', []));
        }

        return response()->json([
            'ok' => true,
            'workspace' => [
                'name' => $w->name,
                'slug' => $w->slug,
                'company_size' => $w->company_size,
                'timezone' => $w->timezone,
                'initial' => $w->initial(),
            ],
            'apps' => $apps->all($w),
        ]);
    }

    /** POST /settings/general/logo */
    public function logo(UploadWorkspaceLogoRequest $request): JsonResponse
    {
        $this->guardManage();
        $w = $this->workspace();

        $path = $request->file('logo')->store("workspace-logos/{$w->id}", 'public');
        $w->forceFill(['logo_url' => Storage::disk('public')->url($path)])->save();

        return response()->json(['ok' => true, 'logo_url' => $w->logo_url]);
    }

    /** DELETE /settings/general — owner only (SET-G-008). */
    public function destroy(WorkspaceDeleter $deleter): RedirectResponse
    {
        $w = $this->workspace();
        abort_unless(Auth::user()->can('delete', $w), 403);

        $name = $w->name;
        $deleter->delete($w);

        return redirect()->route('welcome')->with('status', "Workspace \"{$name}\" deleted.");
    }

    /**
     * Base Web TimezonePicker-style options: every IANA zone as
     * {value: 'America/New_York', label: '(GMT-04:00) America/New York'}, sorted by current
     * UTC offset then name. Offsets reflect the zone's rule as of now (DST-aware). The stored
     * value stays the raw IANA identifier (validated with the timezone rule).
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function timezoneOptions(): array
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $zones = array_map(function (string $tz) use ($now) {
            $offset = (new DateTimeZone($tz))->getOffset($now);
            $sign = $offset < 0 ? '-' : '+';
            $abs = abs($offset);
            $label = sprintf('(GMT%s%02d:%02d) %s', $sign, intdiv($abs, 3600), intdiv($abs % 3600, 60), str_replace('_', ' ', $tz));

            return ['value' => $tz, 'label' => $label, 'offset' => $offset];
        }, DateTimeZone::listIdentifiers());

        usort($zones, fn ($a, $b) => [$a['offset'], $a['value']] <=> [$b['offset'], $b['value']]);

        return array_map(fn ($z) => ['value' => $z['value'], 'label' => $z['label']], $zones);
    }
}
