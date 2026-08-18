<?php

namespace App\Services;

use App\Models\Workspace;
use App\Models\WorkspaceSettings;

/**
 * Which apps a workspace has switched on (docs/features/wiki.md, WIKI-D2).
 *
 * Three screens now ask this question — the create form, Settings → General, and Settings →
 * Wiki — and they have to give the same answer. The mapping from an app key to the flag that
 * records it lives here and nowhere else; a second copy is a second answer waiting to disagree.
 *
 * There is deliberately no `apps` column. Each app already owns a flag on the workspace's
 * settings row, and that flag is what its own screen reads. A list beside them would be a
 * duplicate record of the same fact, free to drift the moment one path updates only one of them.
 */
class WorkspaceApps
{
    /** app key => the `workspace_settings` column that records it. */
    private const FLAGS = [
        'wiki' => 'wiki_enabled',
        // docs/features/help-desk.md FR-1.1. Switching the app on is only that: it makes Help
        // Desk exist for the workspace and puts it in the rail. It grants nobody access to it —
        // Help Desk has its own membership, and enabling is a workspace setting rather than a
        // Help Desk permission (decision H5).
        'helpdesk' => 'help_desk_enabled',
    ];

    public function __construct(private readonly WorkspaceSettingsManager $settings) {}

    /**
     * Every app, with what this workspace has done about it.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(Workspace $workspace): array
    {
        $settings = $this->settings->for($workspace);
        $defaults = (array) config('workspace.default_apps', []);

        return collect(config('workspace.apps'))
            ->map(function (array $app, string $key) use ($settings, $defaults) {
                $isDefault = in_array($key, $defaults, true);

                return [
                    'key' => $key,
                    'label' => $app['label'],
                    'description' => $app['description'],
                    'available' => (bool) ($app['available'] ?? false),
                    // A default app is on because it is what a workspace IS (WIKI-D3), which is
                    // why it also cannot be switched off.
                    'enabled' => $isDefault || $this->readFlag($settings, $key),
                    'locked' => $isDefault,
                ];
            })
            ->values()
            ->all();
    }

    /** App keys that can actually be switched on today. */
    /** @return array<int, string> */
    public function selectable(): array
    {
        return collect(config('workspace.apps'))
            ->filter(fn (array $app, string $key) => ($app['available'] ?? false)
                && ! in_array($key, (array) config('workspace.default_apps', []), true))
            ->keys()
            ->all();
    }

    /**
     * Switch the selectable apps to exactly this list.
     *
     * Anything selectable and absent is turned OFF, so unticking works — but only selectable
     * apps are touched: an unreleased app has no flag to write, and a default one is not the
     * caller's to change.
     *
     * @param  array<int, string>  $keys
     */
    public function sync(Workspace $workspace, array $keys): void
    {
        $changes = [];

        foreach ($this->selectable() as $key) {
            if (! isset(self::FLAGS[$key])) {
                continue;
            }

            $changes[self::FLAGS[$key]] = in_array($key, $keys, true);
        }

        if ($changes === []) {
            return;
        }

        /*
         * Nothing to switch on, and nothing was on before.
         *
         * `settings->for()` PROVISIONS the row on first access — and provisioning also seeds a
         * workspace's default project states and priorities. Reaching for it here to write a
         * set of falses would drag all of that forward to the moment a workspace is created,
         * for a workspace that asked for nothing. It stays lazy, as it was.
         */
        if (! array_filter($changes) && ! $this->hasSettings($workspace)) {
            return;
        }

        $this->settings->for($workspace)->forceFill($changes)->save();
    }

    private function hasSettings(Workspace $workspace): bool
    {
        return WorkspaceSettings::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $workspace->id)
            ->exists();
    }

    /**
     * Is one app on for this workspace, asked from anywhere?
     *
     * Reads the settings row directly, filtered by the workspace's own id, rather than through
     * the tenant-scoped model: the app sidebar asks this on every authenticated page, including
     * ones rendered before `workspace.tenancy` has initialized a context, where the scope would
     * match nothing and every workspace would look like it had Wiki switched off. The explicit
     * `where` IS the isolation (CLAUDE.md §7).
     */
    public function isEnabled(?Workspace $workspace, string $key): bool
    {
        $column = self::FLAGS[$key] ?? null;

        if (! $workspace || ! $column) {
            return in_array($key, (array) config('workspace.default_apps', []), true);
        }

        return (bool) rescue(fn () => WorkspaceSettings::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $workspace->id)
            ->value($column), false, report: false);
    }

    private function readFlag(object $settings, string $key): bool
    {
        $column = self::FLAGS[$key] ?? null;

        return $column ? (bool) $settings->{$column} : false;
    }
}
