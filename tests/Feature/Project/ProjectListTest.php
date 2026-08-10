<?php

namespace Tests\Feature\Project;

use Illuminate\Foundation\Testing\RefreshDatabase;

/** Project list visibility (spec §4.1 / PRJ-010/030/031). */
class ProjectListTest extends ProjectTestCase
{
    use RefreshDatabase;

    public function test_new_project_appears_in_the_list_without_relogin(): void
    {
        [$owner, $workspace] = $this->owner();
        $this->makeProject($owner, $workspace, ['name' => 'Alpha', 'identifier' => 'ALPHA']);

        $this->actingAs($owner)->get(route('projects.index'))->assertOk()->assertSee('Alpha');
    }

    public function test_member_sees_public_but_not_unassigned_private_projects(): void
    {
        [$owner, $workspace] = $this->owner();
        $this->makeProject($owner, $workspace, ['name' => 'PublicOne', 'identifier' => 'PUB', 'visibility' => 'public']);
        $this->makeProject($owner, $workspace, ['name' => 'SecretOne', 'identifier' => 'SEC', 'visibility' => 'private']);

        $member = $this->member($workspace, 'member', 'member@example.com');

        $this->actingAs($member)->get(route('projects.index'))
            ->assertOk()
            ->assertSee('PublicOne')
            ->assertDontSee('SecretOne');
    }

    public function test_guest_sees_only_projects_they_belong_to(): void
    {
        [$owner, $workspace] = $this->owner();
        $this->makeProject($owner, $workspace, ['name' => 'PublicOne', 'identifier' => 'PUB', 'visibility' => 'public']);

        // A guest with no project membership sees no public projects (PRJ-030).
        $guest = $this->member($workspace, 'guest', 'guest@example.com');
        $this->actingAs($guest)->get(route('projects.index'))->assertOk()->assertDontSee('PublicOne');

        // Once made the lead of a project, it shows for them.
        $this->makeProject($owner, $workspace, ['name' => 'GuestLed', 'identifier' => 'GL', 'visibility' => 'private', 'lead_user_id' => $guest->id]);
        $this->actingAs($guest)->get(route('projects.index'))->assertOk()->assertSee('GuestLed');
    }

    public function test_workspace_admin_sees_all_projects(): void
    {
        [$owner, $workspace] = $this->owner();
        $this->makeProject($owner, $workspace, ['name' => 'PublicOne', 'identifier' => 'PUB', 'visibility' => 'public']);
        $this->makeProject($owner, $workspace, ['name' => 'SecretOne', 'identifier' => 'SEC', 'visibility' => 'private']);

        $admin = $this->member($workspace, 'admin', 'admin@example.com');
        $this->actingAs($admin)->get(route('projects.index'))
            ->assertOk()->assertSee('PublicOne')->assertSee('SecretOne');
    }

    public function test_user_without_workspace_is_redirected_to_onboarding(): void
    {
        $stray = \App\Models\User::factory()->create(['current_workspace_id' => null]);

        $this->actingAs($stray)->get(route('projects.index'))->assertRedirect(route('onboarding.workspace'));
    }
}
