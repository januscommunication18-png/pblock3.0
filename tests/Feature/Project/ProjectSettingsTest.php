<?php

namespace Tests\Feature\Project;

use App\Models\Project;
use App\Models\ProjectItemLabel;
use App\Models\ProjectItemState;
use App\Models\ProjectMember;
use Illuminate\Foundation\Testing\RefreshDatabase;

/** Project Settings (PRJ-040..044): general, members, features, states, labels. */
class ProjectSettingsTest extends ProjectTestCase
{
    use RefreshDatabase;

    public function test_update_general_persists_and_identifier_unique_ignores_self(): void
    {
        [$owner, $workspace] = $this->owner();
        $project = $this->makeProject($workspace, $owner, ['identifier' => 'WEB']);

        $this->actingAs($owner)->patchJson(route('projects.settings.general.update', $project), [
            'name' => 'Web App', 'identifier' => 'WEB', 'visibility' => 'private',
            'description' => 'x', 'timezone' => 'UTC',
        ])->assertOk();

        $fresh = $workspace->run(fn () => Project::find($project->id));
        $this->assertSame('Web App', $fresh->name);
        $this->assertSame('private', $fresh->visibility);
    }

    public function test_members_add_role_and_remove_with_last_admin_protected(): void
    {
        [$owner, $workspace] = $this->owner();
        $project = $this->makeProject($workspace, $owner);
        $u = $this->member($workspace, 'member', 'u@example.com');

        // Add u as a member.
        $this->actingAs($owner)->postJson(route('projects.settings.members.store', $project), [
            'user_id' => $u->id, 'role' => 'member',
        ])->assertOk();
        $member = $workspace->run(fn () => ProjectMember::where('project_id', $project->id)->where('user_id', $u->id)->first());
        $ownerMember = $workspace->run(fn () => ProjectMember::where('project_id', $project->id)->where('user_id', $owner->id)->first());

        // Promote u to admin (two admins now), then demote back — both allowed.
        $this->actingAs($owner)->patchJson(route('projects.settings.members.role', ['project' => $project->id, 'member' => $member->id]), ['role' => 'admin'])->assertOk();
        $this->actingAs($owner)->patchJson(route('projects.settings.members.role', ['project' => $project->id, 'member' => $member->id]), ['role' => 'member'])->assertOk();

        // Demoting the creator — the last remaining admin — is blocked.
        $this->actingAs($owner)->patchJson(route('projects.settings.members.role', ['project' => $project->id, 'member' => $ownerMember->id]), ['role' => 'member'])
            ->assertStatus(422);

        // Remove the plain member (not the last admin) — allowed.
        $this->actingAs($owner)->deleteJson(route('projects.settings.members.remove', ['project' => $project->id, 'member' => $member->id]))->assertOk();
        $this->assertFalse($workspace->run(fn () => ProjectMember::whereKey($member->id)->exists()));
    }

    public function test_feature_toggle_persists(): void
    {
        [$owner, $workspace] = $this->owner();
        $project = $this->makeProject($workspace, $owner);

        $this->actingAs($owner)->postJson(route('projects.settings.features.toggle', $project), [
            'feature' => 'intake', 'enabled' => true,
        ])->assertOk()->assertJsonPath('features.intake', true);

        $this->assertTrue($workspace->run(fn () => Project::find($project->id)->featureFlags()['intake']));
    }

    public function test_states_and_labels_crud(): void
    {
        [$owner, $workspace] = $this->owner();
        $project = $this->makeProject($workspace, $owner);

        $this->actingAs($owner)->postJson(route('projects.settings.states.store', $project), [
            'name' => 'Review', 'color' => '#2563EB', 'group' => 'started',
        ])->assertOk();
        $this->assertTrue($workspace->run(fn () => ProjectItemState::where('project_id', $project->id)->where('name', 'Review')->exists()));

        $this->actingAs($owner)->postJson(route('projects.settings.labels.store', $project), [
            'name' => 'Bug', 'color' => '#DC2626',
        ])->assertOk()->assertJsonPath('labels.0.name', 'Bug');
        $label = $workspace->run(fn () => ProjectItemLabel::where('project_id', $project->id)->first());
        $this->actingAs($owner)->deleteJson(route('projects.settings.labels.destroy', ['project' => $project->id, 'label' => $label->id]))->assertOk();
        $this->assertSame(0, $workspace->run(fn () => ProjectItemLabel::where('project_id', $project->id)->count()));
    }

    public function test_non_manager_cannot_change_settings(): void
    {
        [$owner, $workspace] = $this->owner();
        $project = $this->makeProject($workspace, $owner, ['visibility' => 'public']);
        $member = $this->member($workspace, 'member', 'plain@example.com');

        $this->actingAs($member)->postJson(route('projects.settings.features.toggle', $project), ['feature' => 'intake', 'enabled' => true])
            ->assertForbidden();
    }
}
