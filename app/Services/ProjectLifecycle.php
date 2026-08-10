<?php

namespace App\Services;

use App\Models\Project;

/**
 * Project lifecycle transitions (PRJ-045/046/047): archive (soft, data retained), restore,
 * and permanent delete (cascades child rows via FK). Runs within the active tenancy context.
 */
class ProjectLifecycle
{
    public function archive(Project $project): void
    {
        $project->forceFill([
            'status' => Project::STATUS_ARCHIVED,
            'archived_at' => now(),
        ])->save();
    }

    public function restore(Project $project): void
    {
        $project->forceFill([
            'status' => Project::STATUS_ACTIVE,
            'archived_at' => null,
        ])->save();
    }

    /** Permanent delete — cascades project_members, project_item_states/labels via FK. */
    public function delete(Project $project): void
    {
        $project->delete();
    }
}
