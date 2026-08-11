<?php

namespace Tests\Feature\Project;

use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Project card action menu — Edit / Archive|Restore / Delete (PRJ-045/046/047).
 *
 * Covers the endpoints the card dropdown calls, including the manage-rights checks: the
 * menu is only rendered client-side for managers, so the server must reject everyone else.
 */
class ProjectCardActionsTest extends ProjectTestCase
{
    use RefreshDatabase;

    public function test_index_exposes_the_card_action_endpoints(): void
    {
        [$owner] = $this->owner();

        $this->actingAs($owner)->get(route('projects.index'))
            ->assertOk()
            ->assertViewHas('bootstrap', function ($b) {
                $e = $b['endpoints'];

                return str_contains($e['archive'], '__ID__')
                    && str_contains($e['restore'], '__ID__')
                    && str_contains($e['destroy'], '__ID__');
            });
    }

    public function test_manager_can_archive_and_restore_from_the_card(): void
    {
        [$owner, $workspace] = $this->owner();
        $project = $this->makeProject($owner, $workspace);

        $this->actingAs($owner)->postJson(route('projects.archive', $project))->assertOk();
        $this->assertSame('archived', $workspace->run(fn () => Project::find($project->id)->status));

        $this->actingAs($owner)->postJson(route('projects.restore', $project))->assertOk();
        $this->assertSame('active', $workspace->run(fn () => Project::find($project->id)->status));
    }

    public function test_delete_needs_the_typed_identifier(): void
    {
        [$owner, $workspace] = $this->owner();
        $project = $this->makeProject($owner, $workspace, ['identifier' => 'DEL']);

        // Wrong text → rejected, project untouched.
        $this->actingAs($owner)->deleteJson(route('projects.destroy', $project), ['confirm' => 'nope'])
            ->assertStatus(422);
        $this->assertTrue($workspace->run(fn () => Project::whereKey($project->id)->exists()));

        // Correct identifier (typed lowercase, as the card shows it) → deleted.
        $this->actingAs($owner)->deleteJson(route('projects.destroy', $project), ['confirm' => 'del'])
            ->assertOk();
        $this->assertFalse($workspace->run(fn () => Project::whereKey($project->id)->exists()));
    }

    public function test_non_manager_cannot_archive_or_delete(): void
    {
        [$owner, $workspace] = $this->owner();
        $project = $this->makeProject($owner, $workspace, ['identifier' => 'NOP']);
        $member = $this->member($workspace, 'member', 'member@example.com');

        $this->actingAs($member)->postJson(route('projects.archive', $project))->assertForbidden();
        $this->actingAs($member)->deleteJson(route('projects.destroy', $project), ['confirm' => 'NOP'])
            ->assertForbidden();
        $this->assertTrue($workspace->run(fn () => Project::whereKey($project->id)->exists()));
    }
}
