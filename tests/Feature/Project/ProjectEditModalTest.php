<?php

namespace Tests\Feature\Project;

use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\ProjectPriority;
use App\Models\ProjectState;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Edit project from the card's action menu (PRJ-045) — the modal loads every field and
 * saves them in a single PATCH /projects/{project}, including the four chips (status,
 * priority, start date, end date) that the card otherwise edits one at a time.
 */
class ProjectEditModalTest extends ProjectTestCase
{
    use RefreshDatabase;

    /** @return array{0: int, 1: int} state id, priority id */
    private function seedOptions($workspace): array
    {
        return $workspace->run(function () {
            $state = ProjectState::create(['name' => 'In Progress', 'color' => '#F59E0B', 'position' => 1]);
            $priority = ProjectPriority::create(['name' => 'Urgent', 'color' => '#EF4444', 'position' => 0]);

            return [$state->id, $priority->id];
        });
    }

    public function test_index_exposes_the_update_endpoint(): void
    {
        [$owner] = $this->owner();

        $this->actingAs($owner)->get(route('projects.index'))
            ->assertOk()
            ->assertViewHas('bootstrap', fn ($b) => str_contains((string) $b['endpoints']['update'], '__ID__'));
    }

    public function test_card_payload_carries_every_field_the_modal_loads(): void
    {
        [$owner, $ws] = $this->owner();
        [$stateId, $priorityId] = $this->seedOptions($ws);
        $project = $this->makeProject($owner, $ws, ['identifier' => 'WEB']);

        $ws->run(fn () => Project::whereKey($project->id)->update([
            'state_id' => $stateId, 'priority_id' => $priorityId,
            'start_date' => '2026-09-01', 'end_date' => '2026-09-30',
            'lead_user_id' => $owner->id,
        ]));

        $this->actingAs($owner)->get(route('projects.index'))
            ->assertOk()
            ->assertViewHas('bootstrap', function ($b) use ($stateId, $priorityId, $owner) {
                $card = $b['projects'][0];

                return $card['state']['id'] === $stateId
                    && $card['priority']['id'] === $priorityId
                    && $card['lead']['id'] === $owner->id
                    && $card['start_date'] === '2026-09-01'
                    && $card['end_date'] === '2026-09-30';
            });
    }

    public function test_manager_can_save_every_field_in_one_request(): void
    {
        [$owner, $ws] = $this->owner();
        [$stateId, $priorityId] = $this->seedOptions($ws);
        $project = $this->makeProject($owner, $ws, ['identifier' => 'WEB']);

        $this->actingAs($owner)
            ->patchJson(route('projects.update', ['project' => $project->id]), [
                'name' => 'Website Rebuild',
                'description' => 'Now with a plan.',
                'visibility' => 'private',
                'lead_user_id' => $owner->id,
                'state_id' => $stateId,
                'priority_id' => $priorityId,
                'start_date' => '2026-09-01',
                'end_date' => '2026-09-30',
            ])
            ->assertOk()
            ->assertJsonPath('project.name', 'Website Rebuild')
            ->assertJsonPath('project.identifier', 'web')
            ->assertJsonPath('project.state.id', $stateId)
            ->assertJsonPath('project.priority.id', $priorityId)
            ->assertJsonPath('project.lead.id', $owner->id)
            ->assertJsonPath('project.start_date', '2026-09-01')
            ->assertJsonPath('project.end_date', '2026-09-30');

        $fresh = $ws->run(fn () => Project::find($project->id));
        $this->assertSame('private', $fresh->visibility);
        $this->assertSame('Now with a plan.', $fresh->description);
        $this->assertSame($stateId, $fresh->state_id);
        $this->assertSame($priorityId, $fresh->priority_id);
    }

    public function test_cleared_pickers_are_saved_as_null(): void
    {
        [$owner, $ws] = $this->owner();
        [$stateId, $priorityId] = $this->seedOptions($ws);
        $project = $this->makeProject($owner, $ws, ['identifier' => 'WEB']);

        $ws->run(fn () => Project::whereKey($project->id)->update([
            'state_id' => $stateId, 'priority_id' => $priorityId,
            'start_date' => '2026-09-01', 'end_date' => '2026-09-30',
            'lead_user_id' => $owner->id,
        ]));

        // The modal posts "" for every picker the user cleared.
        $this->actingAs($owner)
            ->patchJson(route('projects.update', ['project' => $project->id]), [
                'name' => 'Website Redesign', 'identifier' => 'WEB', 'visibility' => 'public',
                'description' => '', 'lead_user_id' => '', 'state_id' => '', 'priority_id' => '',
                'start_date' => '', 'end_date' => '',
            ])
            ->assertOk()
            ->assertJsonPath('project.priority', null)
            ->assertJsonPath('project.lead', null)
            ->assertJsonPath('project.start_date', null)
            ->assertJsonPath('project.end_date', null);

        $fresh = $ws->run(fn () => Project::find($project->id));
        $this->assertNull($fresh->state_id);
        $this->assertNull($fresh->priority_id);
        $this->assertNull($fresh->lead_user_id);
    }

    public function test_status_and_priority_must_belong_to_the_workspace(): void
    {
        [$owner, $ws] = $this->owner();
        $this->seedOptions($ws);
        $project = $this->makeProject($owner, $ws, ['identifier' => 'WEB']);
        $base = ['name' => 'Website Redesign', 'identifier' => 'WEB', 'visibility' => 'public'];

        $this->actingAs($owner)
            ->patchJson(route('projects.update', ['project' => $project->id]), $base + ['state_id' => 999999])
            ->assertStatus(422);

        $this->actingAs($owner)
            ->patchJson(route('projects.update', ['project' => $project->id]), $base + ['priority_id' => 999999])
            ->assertStatus(422);
    }

    public function test_due_date_cannot_precede_the_start_date(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'WEB']);

        $this->actingAs($owner)
            ->patchJson(route('projects.update', ['project' => $project->id]), [
                'name' => 'Website Redesign', 'identifier' => 'WEB', 'visibility' => 'public',
                'start_date' => '2026-09-30', 'end_date' => '2026-09-01',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('end_date');
    }

    public function test_identifier_is_read_only_and_never_changes(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'WEB']);

        // The modal renders the field read-only and omits it from the payload; a posted
        // identifier must still be ignored rather than trusted.
        $this->actingAs($owner)
            ->patchJson(route('projects.update', ['project' => $project->id]), [
                'name' => 'Website Rebuild', 'identifier' => 'HACKED', 'visibility' => 'public',
            ])
            ->assertOk()
            ->assertJsonPath('project.identifier', 'web');

        $this->assertSame('web', $ws->run(fn () => Project::find($project->id)->identifier));

        // Saving without the field at all is valid — it is not a required input.
        $this->actingAs($owner)
            ->patchJson(route('projects.update', ['project' => $project->id]), [
                'name' => 'Website Redesign', 'visibility' => 'public',
            ])
            ->assertOk()
            ->assertJsonPath('project.identifier', 'web');
    }

    public function test_non_manager_cannot_update(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'WEB']);
        $member = $this->member($ws, 'member', 'member@example.com');

        $this->actingAs($member)
            ->patchJson(route('projects.update', ['project' => $project->id]), [
                'name' => 'Hijacked', 'identifier' => 'WEB', 'visibility' => 'public',
            ])
            ->assertForbidden();

        $this->assertSame('Website Redesign', $ws->run(fn () => Project::find($project->id)->name));
    }

    public function test_editing_is_workspace_owner_admin_only(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'WEB']);

        // A project admin who is only a workspace *member*: keeps manage rights (archive /
        // delete) but must not be offered Edit, nor be able to call the endpoint.
        $projectAdmin = $this->member($ws, 'member', 'proj-admin@example.com');
        $ws->run(fn () => ProjectMember::create([
            'project_id' => $project->id, 'user_id' => $projectAdmin->id,
            'role' => ProjectMember::ROLE_ADMIN,
        ]));

        $this->actingAs($projectAdmin)->get(route('projects.index'))
            ->assertOk()
            ->assertViewHas('bootstrap', function ($b) {
                $card = $b['projects'][0];

                return $card['can_manage'] === true && $card['can_edit'] === false;
            });

        $this->actingAs($projectAdmin)
            ->patchJson(route('projects.update', ['project' => $project->id]), [
                'name' => 'Hijacked', 'visibility' => 'public',
            ])
            ->assertForbidden();

        // A workspace admin sees Edit and can save.
        $wsAdmin = $this->member($ws, 'admin', 'ws-admin@example.com');

        $this->actingAs($wsAdmin)->get(route('projects.index'))
            ->assertOk()
            ->assertViewHas('bootstrap', fn ($b) => $b['projects'][0]['can_edit'] === true);

        $this->actingAs($wsAdmin)
            ->patchJson(route('projects.update', ['project' => $project->id]), [
                'name' => 'Website Rebuild', 'visibility' => 'public',
            ])
            ->assertOk();

        $this->assertSame('Website Rebuild', $ws->run(fn () => Project::find($project->id)->name));
    }
}
