<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectItemState;
use Illuminate\Support\Collection;

/**
 * Provisions a project's work-item states on first use (Phase 5).
 *
 * `project_item_states` shipped in Phase 4 but nothing seeded it, so every project starts
 * with an empty state set and the Work Items list would have nothing to group by. Seeding
 * lazily — rather than only in ProjectCreator — means projects created before Phase 5 get
 * their states the first time someone opens Work Items, with no backfill migration.
 *
 * Mirrors WorkspaceSettingsManager::for(): idempotent, and never touches a project that has
 * already been customized in Project Settings → States.
 */
class ProjectItemStateProvisioner
{
    /**
     * The project's ordered states, seeding the configured defaults if it has none.
     *
     * @return Collection<int, ProjectItemState>
     */
    public function for(Project $project): Collection
    {
        $states = $this->ordered($project);

        if ($states->isNotEmpty()) {
            return $states;
        }

        foreach (array_values(config('projects.default_item_states') ?? []) as $position => $state) {
            ProjectItemState::create([
                'project_id' => $project->id,
                'name' => $state['name'],
                'color' => $state['color'],
                'group' => $state['group'],
                'is_default' => $state['is_default'],
                'position' => $position,
            ]);
        }

        return $this->ordered($project);
    }

    /** The project's default state — the one a new work item lands in (spec §4.3). */
    public function defaultState(Project $project): ?ProjectItemState
    {
        $states = $this->for($project);

        return $states->firstWhere('is_default', true) ?? $states->first();
    }

    /** @return Collection<int, ProjectItemState> */
    private function ordered(Project $project): Collection
    {
        return ProjectItemState::query()
            ->where('project_id', $project->id)
            ->orderBy('position')
            ->get();
    }
}
