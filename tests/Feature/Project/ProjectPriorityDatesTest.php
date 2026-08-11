<?php

namespace Tests\Feature\Project;

use App\Models\Project;
use App\Models\ProjectPriority;
use Illuminate\Foundation\Testing\RefreshDatabase;

/** Editable priority + start/end date chips on project cards. */
class ProjectPriorityDatesTest extends ProjectTestCase
{
    use RefreshDatabase;

    private function seedPriorities($workspace): array
    {
        return $workspace->run(function () {
            $u = ProjectPriority::create(['name' => 'Urgent', 'color' => '#EF4444', 'position' => 0]);
            $l = ProjectPriority::create(['name' => 'Low', 'color' => '#3B82F6', 'position' => 1]);

            return [$u->id, $l->id];
        });
    }

    public function test_owner_can_set_and_clear_priority(): void
    {
        [$owner, $ws] = $this->owner();
        [$urgentId] = $this->seedPriorities($ws);
        $project = $this->makeProject($owner, $ws, ['name' => 'Site', 'identifier' => 'SITE']);

        $this->actingAs($owner)
            ->patchJson(route('projects.priority', ['project' => $project->id]), ['priority_id' => $urgentId])
            ->assertOk()->assertJsonPath('priority.id', $urgentId)->assertJsonPath('priority.name', 'Urgent');

        $this->actingAs($owner)
            ->patchJson(route('projects.priority', ['project' => $project->id]), ['priority_id' => ''])
            ->assertOk()->assertJsonPath('priority', null);
    }

    public function test_priority_must_belong_to_workspace(): void
    {
        [$owner, $ws] = $this->owner();
        $this->seedPriorities($ws);
        $project = $this->makeProject($owner, $ws, ['name' => 'Site', 'identifier' => 'SITE']);

        $this->actingAs($owner)
            ->patchJson(route('projects.priority', ['project' => $project->id]), ['priority_id' => 999999])
            ->assertStatus(422);
    }

    public function test_owner_can_set_start_and_end_dates(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['name' => 'Site', 'identifier' => 'SITE']);

        $this->actingAs($owner)
            ->patchJson(route('projects.dates', ['project' => $project->id]), ['start_date' => '2026-08-20', 'end_date' => '2026-09-10'])
            ->assertOk()
            ->assertJsonPath('start_date', '2026-08-20')
            ->assertJsonPath('end_date', '2026-09-10');

        $this->assertSame('2026-08-20', $ws->run(fn () => substr((string) Project::find($project->id)->start_date, 0, 10)));
    }

    public function test_end_date_cannot_precede_start_date(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['name' => 'Site', 'identifier' => 'SITE']);

        $this->actingAs($owner)
            ->patchJson(route('projects.dates', ['project' => $project->id]), ['start_date' => '2026-09-10', 'end_date' => '2026-08-01'])
            ->assertStatus(422);
    }

    public function test_member_cannot_set_priority_or_dates(): void
    {
        [$owner, $ws] = $this->owner();
        [$urgentId] = $this->seedPriorities($ws);
        $project = $this->makeProject($owner, $ws, ['name' => 'Site', 'identifier' => 'SITE']);
        $member = $this->member($ws, 'member', 'm@example.com');

        $this->actingAs($member)
            ->patchJson(route('projects.priority', ['project' => $project->id]), ['priority_id' => $urgentId])
            ->assertForbidden();
        $this->actingAs($member)
            ->patchJson(route('projects.dates', ['project' => $project->id]), ['start_date' => '2026-08-20'])
            ->assertForbidden();
    }
}
