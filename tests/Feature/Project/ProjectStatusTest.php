<?php

namespace Tests\Feature\Project;

use App\Models\Project;
use App\Models\ProjectState;
use Illuminate\Foundation\Testing\RefreshDatabase;

/** Editable per-project status chip sourced from Settings → Projects states. */
class ProjectStatusTest extends ProjectTestCase
{
    use RefreshDatabase;

    private function seedStates($workspace): array
    {
        return $workspace->run(function () {
            $a = ProjectState::create(['name' => 'In Progress', 'color' => '#2563eb', 'group' => 'started', 'is_default' => true, 'position' => 0]);
            $b = ProjectState::create(['name' => 'Completed', 'color' => '#16a34a', 'group' => 'completed', 'is_default' => false, 'position' => 1]);

            return [$a->id, $b->id];
        });
    }

    public function test_new_project_defaults_to_default_state_and_card_exposes_it(): void
    {
        [$owner, $ws] = $this->owner();
        [$defaultId] = $this->seedStates($ws);

        $resp = $this->actingAs($owner)->postJson(route('projects.store'), [
            'name' => 'Website', 'identifier' => 'WEB', 'visibility' => 'public',
        ])->assertOk();

        $resp->assertJsonPath('project.state.id', $defaultId);
        $resp->assertJsonPath('project.state.name', 'In Progress');
        $resp->assertJsonPath('project.can_manage', true);
    }

    public function test_project_created_before_states_seeded_still_gets_a_default_status(): void
    {
        // The exact reported case: brand-new workspace, no states touched yet. Creation
        // should provision the workspace defaults and assign one, so status is never null.
        [$owner] = $this->owner();

        $resp = $this->actingAs($owner)->postJson(route('projects.store'), [
            'name' => 'Fresh', 'identifier' => 'FRESH', 'visibility' => 'public',
        ])->assertOk();

        $state = $resp->json('project.state');
        $this->assertIsArray($state, 'A newly created project should have a default status.');
        $this->assertNotEmpty($state['name'] ?? null);
    }

    public function test_owner_can_change_project_status(): void
    {
        [$owner, $ws] = $this->owner();
        [, $completedId] = $this->seedStates($ws);
        $project = $this->makeProject($ws, $owner, ['name' => 'Site', 'identifier' => 'SITE']);

        $this->actingAs($owner)
            ->patchJson(route('projects.state', ['project' => $project->id]), ['state_id' => $completedId])
            ->assertOk()
            ->assertJsonPath('state.id', $completedId)
            ->assertJsonPath('state.name', 'Completed');

        $this->assertSame($completedId, $ws->run(fn () => Project::find($project->id)->state_id));
    }

    public function test_clearing_reverts_to_the_default_state(): void
    {
        [$owner, $ws] = $this->owner();
        [$defaultId] = $this->seedStates($ws);
        $project = $this->makeProject($ws, $owner, ['name' => 'Site', 'identifier' => 'SITE']);

        // Clearing the explicit status falls back to the workspace default (not "no status").
        $this->actingAs($owner)
            ->patchJson(route('projects.state', ['project' => $project->id]), ['state_id' => ''])
            ->assertOk()
            ->assertJsonPath('state.id', $defaultId);
    }

    public function test_member_cannot_change_status(): void
    {
        [$owner, $ws] = $this->owner();
        [, $completedId] = $this->seedStates($ws);
        $project = $this->makeProject($ws, $owner, ['name' => 'Site', 'identifier' => 'SITE']);
        $member = $this->member($ws, 'member', 'm@example.com');

        $this->actingAs($member)
            ->patchJson(route('projects.state', ['project' => $project->id]), ['state_id' => $completedId])
            ->assertForbidden();
    }
}
