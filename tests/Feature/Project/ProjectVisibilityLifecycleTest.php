<?php

namespace Tests\Feature\Project;

use App\Models\Project;
use App\Models\ProjectItemLabel;
use App\Models\ProjectItemState;
use App\Models\ProjectMember;
use Illuminate\Foundation\Testing\RefreshDatabase;

/** Visibility enforcement (PRJ-030/031/032) + lifecycle (PRJ-045/046/047). */
class ProjectVisibilityLifecycleTest extends ProjectTestCase
{
    use RefreshDatabase;

    public function test_private_project_is_hidden_from_a_non_member_but_visible_to_owner(): void
    {
        [$owner, $workspace] = $this->owner();
        $project = $this->makeProject($owner, $workspace, ['identifier' => 'PRIV', 'visibility' => 'private']);
        $stranger = $this->member($workspace, 'member', 'stranger@example.com');

        $this->actingAs($stranger)->get(route('projects.show', $project))->assertNotFound();
        $this->actingAs($owner)->followingRedirects()->get(route('projects.show', $project))->assertOk();
    }

    public function test_public_project_is_visible_to_a_standard_member(): void
    {
        [$owner, $workspace] = $this->owner();
        $project = $this->makeProject($owner, $workspace, ['identifier' => 'PUB', 'visibility' => 'public']);
        $member = $this->member($workspace, 'member', 'member@example.com');

        $this->actingAs($member)->followingRedirects()->get(route('projects.show', $project))->assertOk();
    }

    public function test_archive_then_restore(): void
    {
        [$owner, $workspace] = $this->owner();
        $project = $this->makeProject($owner, $workspace);

        $this->actingAs($owner)->postJson(route('projects.archive', $project))->assertOk();
        $this->assertSame('archived', $workspace->run(fn () => Project::find($project->id)->status));
        // Active list excludes it; archived list includes it.
        $this->actingAs($owner)->get(route('projects.index'))
            ->assertViewHas('bootstrap', fn ($b) => count($b['projects']) === 0);
        $this->actingAs($owner)->get(route('projects.index', ['archived' => 1]))
            ->assertViewHas('bootstrap', fn ($b) => count($b['projects']) === 1);

        $this->actingAs($owner)->postJson(route('projects.restore', $project))->assertOk();
        $this->assertSame('active', $workspace->run(fn () => Project::find($project->id)->status));
    }

    public function test_delete_requires_typed_identifier_and_cascades(): void
    {
        [$owner, $workspace] = $this->owner();
        $project = $this->makeProject($owner, $workspace, ['identifier' => 'DEL']);
        $workspace->run(fn () => ProjectItemLabel::create(['project_id' => $project->id, 'name' => 'L', 'color' => '#22C55E', 'position' => 1]));

        // Wrong confirmation → rejected.
        $this->actingAs($owner)->deleteJson(route('projects.destroy', $project), ['confirm' => 'nope'])->assertStatus(422);
        $this->assertTrue($workspace->run(fn () => Project::whereKey($project->id)->exists()));

        // Correct confirmation → deletes project + child rows.
        $this->actingAs($owner)->deleteJson(route('projects.destroy', $project), ['confirm' => 'DEL'])->assertOk();
        $this->assertFalse($workspace->run(fn () => Project::whereKey($project->id)->exists()));
        $this->assertSame(0, $workspace->run(fn () => ProjectMember::where('project_id', $project->id)->count()));
        $this->assertSame(0, $workspace->run(fn () => ProjectItemState::where('project_id', $project->id)->count()));
    }

    public function test_projects_are_isolated_between_workspaces(): void
    {
        [$ownerA, $wsA] = $this->owner('acme');
        [$ownerB] = $this->owner('beta');
        $projectA = $this->makeProject($ownerA, $wsA, ['identifier' => 'AON']);

        // Owner B (different active workspace) cannot open or archive A's project — 404 via tenant scope.
        $this->actingAs($ownerB)->get(route('projects.show', $projectA))->assertNotFound();
        $this->actingAs($ownerB)->postJson(route('projects.archive', $projectA))->assertNotFound();
    }
}
