<?php

namespace App\Services;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates a workspace atomically together with its Owner membership and sets it as the
 * creator's active workspace (spec §3 / WS-007..WS-010).
 *
 * Everything runs in a single transaction so a failure rolls back the workspace and the
 * owner membership together (spec §9). The unique `slug` constraint plus the transaction
 * make double-submits safe (WS-010): a racing duplicate slug fails the second insert.
 */
class WorkspaceCreator
{
    public function __construct(private readonly WorkspaceSettingsManager $settings) {}

    /**
     * @param  array{name:string, slug:string, company_size:string, view_type?:string, timezone?:string|null, apps?:array<int, string>}  $data
     */
    public function create(User $creator, array $data): Workspace
    {
        $viewType = $data['view_type'] ?? config('workspace.default_view');

        // WS-VIEW-003: server rejects any non-available view (e.g. classic before release),
        // regardless of what the client submitted.
        $view = config("workspace.views.{$viewType}");
        if (! $view || ! $view['available']) {
            throw ValidationException::withMessages([
                'view_type' => ["The {$viewType} workspace view is not available yet."],
            ]);
        }

        return DB::transaction(function () use ($creator, $data, $viewType) {
            /** @var Workspace $workspace */
            $workspace = Workspace::create([
                'name' => $data['name'],
                'slug' => $data['slug'],
                'company_size' => $data['company_size'],
                'timezone' => $data['timezone'] ?? null,
                'view_type' => $viewType,
                'status' => 'active',
                'created_by' => $creator->id,
            ]);

            WorkspaceMembership::create([
                'workspace_id' => $workspace->id,
                'user_id' => $creator->id,
                'role' => WorkspaceMembership::ROLE_OWNER, // WS-008
                'status' => WorkspaceMembership::STATUS_ACTIVE,
                'joined_at' => now(),
            ]);

            // WS-009: the new workspace becomes the creator's active/last-active workspace.
            $creator->forceFill(['current_workspace_id' => $workspace->id])->save();

            $this->enableApps($workspace, $data['apps'] ?? config('workspace.default_apps', []));

            return $workspace;
        });
    }

    /**
     * Switch on the apps chosen at creation (wiki WIKI-D1/WIKI-D2).
     *
     * Written to the workspace's own settings row — the SAME flag Settings → Wiki toggles —
     * rather than to a list of apps kept beside it. Two records of "is Wiki on?" is two records
     * free to disagree, and the screens would each believe a different one.
     *
     * Projects needs nothing switched on: it is what a workspace is (WIKI-D3).
     *
     * @param  array<int, string>  $apps
     */
    private function enableApps(Workspace $workspace, array $apps): void
    {
        $flags = array_filter([
            'wiki_enabled' => in_array('wiki', $apps, true),
        ]);

        if ($flags === []) {
            return;
        }

        // Provisions the settings row on first use, inside the workspace's tenancy context.
        $this->settings->for($workspace)->forceFill($flags)->save();
    }
}
