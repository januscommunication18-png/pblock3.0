<?php

namespace Tests\Feature\Project;

use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;

/** Editable per-project lead combo on the card. */
class ProjectLeadTest extends ProjectTestCase
{
    use RefreshDatabase;

    public function test_owner_can_set_and_clear_lead(): void
    {
        [$owner, $ws] = $this->owner();
        $alice = $this->member($ws, 'member', 'alice@example.com');
        $project = $this->makeProject($owner, $ws, ['name' => 'Site', 'identifier' => 'SITE']);

        // set
        $this->actingAs($owner)
            ->patchJson(route('projects.lead', ['project' => $project->id]), ['lead_user_id' => $alice->id])
            ->assertOk()
            ->assertJsonPath('lead.id', $alice->id);

        $this->assertSame($alice->id, $ws->run(fn () => Project::find($project->id)->lead_user_id));

        // clear
        $this->actingAs($owner)
            ->patchJson(route('projects.lead', ['project' => $project->id]), ['lead_user_id' => ''])
            ->assertOk()
            ->assertJsonPath('lead', null);
    }

    public function test_lead_must_be_a_workspace_member(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['name' => 'Site', 'identifier' => 'SITE']);

        $this->actingAs($owner)
            ->patchJson(route('projects.lead', ['project' => $project->id]), ['lead_user_id' => 999999])
            ->assertStatus(422);
    }

    public function test_non_manager_cannot_set_lead(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['name' => 'Site', 'identifier' => 'SITE']);
        $member = $this->member($ws, 'member', 'm@example.com');

        $this->actingAs($member)
            ->patchJson(route('projects.lead', ['project' => $project->id]), ['lead_user_id' => $member->id])
            ->assertForbidden();
    }
}
