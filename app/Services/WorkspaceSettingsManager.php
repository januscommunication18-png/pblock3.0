<?php

namespace App\Services;

use App\Models\ProjectPriority;
use App\Models\ProjectState;
use App\Models\Workspace;
use App\Models\WorkspaceSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Resolves (and lazily provisions) the per-workspace settings singleton and its seeded
 * defaults (spec §6-§11).
 *
 * Runs inside the workspace's tenancy context so BelongsToTenant stamps/scopes tenant_id
 * automatically. On first provision it creates the workspace_settings row from the
 * configured feature defaults and seeds the six approved default project states
 * (SET-PROJ-001) — exactly once per workspace.
 */
class WorkspaceSettingsManager
{
    /** Get-or-create the settings row for the workspace, seeding defaults on first use. */
    public function for(Workspace $workspace): WorkspaceSettings
    {
        return $workspace->run(function () use ($workspace) {
            $existing = WorkspaceSettings::query()->first();
            if ($existing) {
                return $existing;
            }

            return DB::transaction(function () use ($workspace) {
                $settings = WorkspaceSettings::create(
                    array_merge(config('settings.feature_defaults'), ['tenant_id' => $workspace->id])
                );

                $this->seedDefaultProjectStates();
                $this->seedDefaultProjectPriorities();

                return $settings;
            });
        });
    }

    /** Seed the default project priorities if none exist yet (idempotent). */
    public function seedDefaultProjectPriorities(): void
    {
        // Guarded so an un-migrated database (table missing) can't break provisioning.
        if (! Schema::hasTable('project_priorities') || ProjectPriority::query()->exists()) {
            return;
        }

        foreach (array_values(config('settings.default_project_priorities', [])) as $i => $p) {
            ProjectPriority::create([
                'name' => $p['name'],
                'color' => $p['color'],
                'position' => $i,
            ]);
        }
    }

    /** Seed the six approved default project states if none exist yet (idempotent). */
    private function seedDefaultProjectStates(): void
    {
        if (ProjectState::query()->exists()) {
            return;
        }

        foreach (array_values(config('settings.default_project_states')) as $i => $state) {
            ProjectState::create([
                'name' => $state['name'],
                'color' => $state['color'],
                'group' => $state['group'],
                'is_default' => $state['is_default'],
                'position' => $i,
            ]);
        }
    }
}
