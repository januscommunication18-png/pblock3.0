<?php

namespace Tests\Feature\Project;

use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;

/** Visibility & membership enforcement (spec §4.3 / PRJ-030/031/032). */
class ProjectAccessTest extends ProjectTestCase
{
    use RefreshDatabase;

    public function test_creator_can_open_their_private_project(): void
    {
        [$owner, $workspace] = $this->owner();
        $project = $this->makeProject($owner, $workspace, ['identifier' => 'PRV', 'visibility' => 'private']);

        $this->actingAs($owner)->get(route('projects.show', $project->id))->assertOk();
    }

    public function test_unassigned_member_cannot_open_a_private_project_by_url(): void
    {
        [$owner, $workspace] = $this->owner();
        $project = $this->makeProject($owner, $workspace, ['identifier' => 'PRV', 'visibility' => 'private']);

        // A standard member who is not on the project — must 404 (no metadata leak, PRJ-031/032).
        $member = $this->member($workspace, 'member', 'member@example.com');
        $this->actingAs($member)->get(route('projects.show', $project->id))->assertNotFound();
    }

    public function test_workspace_admin_retains_access_to_private_projects(): void
    {
        [$owner, $workspace] = $this->owner();
        $project = $this->makeProject($owner, $workspace, ['identifier' => 'PRV', 'visibility' => 'private']);

        $admin = $this->member($workspace, 'admin', 'admin@example.com');
        $this->actingAs($admin)->get(route('projects.show', $project->id))->assertOk();
    }

    public function test_assigned_lead_can_open_a_private_project(): void
    {
        [$owner, $workspace] = $this->owner();
        $lead = $this->member($workspace, 'member', 'lead@example.com');
        $project = $this->makeProject($owner, $workspace, [
            'identifier' => 'PRV', 'visibility' => 'private', 'lead_user_id' => $lead->id,
        ]);

        $this->actingAs($lead)->get(route('projects.show', $project->id))->assertOk();
    }

    public function test_standard_member_can_open_a_public_project_but_guest_cannot(): void
    {
        [$owner, $workspace] = $this->owner();
        $project = $this->makeProject($owner, $workspace, ['identifier' => 'PUB', 'visibility' => 'public']);

        $member = $this->member($workspace, 'member', 'member@example.com');
        $this->actingAs($member)->get(route('projects.show', $project->id))->assertOk();

        // A guest is NOT granted access just because the project is public (PRJ-030).
        $guest = $this->member($workspace, 'guest', 'guest@example.com');
        $this->actingAs($guest)->get(route('projects.show', $project->id))->assertNotFound();
    }

    public function test_project_is_isolated_from_another_workspace(): void
    {
        [$ownerA, $wsA] = $this->owner('ws-a');
        [$ownerB] = $this->owner('ws-b');
        $projectA = $this->makeProject($ownerA, $wsA, ['identifier' => 'AAA', 'visibility' => 'public']);

        // Owner B's active workspace is B; A's project id resolves to nothing under B's tenancy.
        $this->actingAs($ownerB)->get(route('projects.show', $projectA->id))->assertNotFound();
    }

    public function test_visibility_persists(): void
    {
        [$owner, $workspace] = $this->owner();
        $this->makeProject($owner, $workspace, ['identifier' => 'PRV', 'visibility' => 'private']);

        $this->assertSame('private', $workspace->run(fn () => Project::first()->visibility));
    }
}
