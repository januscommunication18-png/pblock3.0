<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Creates a project atomically inside a workspace together with its seed memberships
 * (spec §4.2 / PRJ-028).
 *
 * Runs inside the workspace's tenancy context ($workspace->run) so BelongsToTenant stamps
 * `tenant_id` on the project and its members automatically. Everything is one transaction:
 * a failure (including a racing duplicate identifier, which the UNIQUE(tenant_id,
 * identifier) constraint rejects) rolls the whole thing back and no partial project is
 * persisted (spec §7). The creator is seeded as project admin and the chosen lead as a
 * member so a private project is immediately accessible to both (PRJ-031).
 */
class ProjectCreator
{
    /**
     * @param  array{name:string, identifier:string, description:?string, visibility:string, lead_user_id:?int}  $data
     */
    public function create(User $creator, Workspace $workspace, array $data, ?UploadedFile $cover = null): Project
    {
        // Store the cover outside the transaction — a filesystem write should not hold a DB
        // lock, and a failed upload must not erase the rest of the form (spec §7).
        $coverUrl = $cover ? $this->storeCover($workspace, $cover) : null;

        return $workspace->run(function () use ($creator, $workspace, $data, $coverUrl) {
            return DB::transaction(function () use ($creator, $workspace, $data, $coverUrl) {
                /** @var Project $project */
                $project = Project::create([
                    'name' => $data['name'],
                    'identifier' => $data['identifier'],
                    'description' => $data['description'] ?? null,
                    'visibility' => $data['visibility'],
                    'lead_user_id' => $data['lead_user_id'] ?? null,
                    'cover_url' => $coverUrl,
                    'timezone' => $workspace->timezone, // PRJ-040: default from the workspace
                    'status' => Project::STATUS_ACTIVE,
                    'created_by' => $creator->id,
                ]);

                $this->seedMembers($project, $creator->id, $data['lead_user_id'] ?? null);

                return $project;
            });
        });
    }

    /**
     * Seed the creator and the lead as project members.
     *
     * §19: the creator automatically becomes Project Admin and must never have to add
     * themselves — which is also what guarantees §20's "at least one Project Admin" holds
     * from the moment a project exists. §13 of the member spec makes the lead a Contributor.
     */
    private function seedMembers(Project $project, int $creatorId, ?int $leadId): void
    {
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $creatorId,
            'added_by' => $creatorId,
            'role' => ProjectMember::ROLE_ADMIN,
        ]);

        if ($leadId !== null && (int) $leadId !== $creatorId) {
            ProjectMember::create([
                'project_id' => $project->id,
                'user_id' => $leadId,
                'added_by' => $creatorId,
                'role' => ProjectMember::ROLE_CONTRIBUTOR,
            ]);
        }
    }

    private function storeCover(Workspace $workspace, UploadedFile $cover): string
    {
        $path = $cover->store("project-covers/{$workspace->id}", 'public');

        return Storage::disk('public')->url($path);
    }
}
