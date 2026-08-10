<?php

namespace Tests\Feature\Project;

use App\Models\Project;
use App\Models\ProjectMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/** Create Project MVP (spec §4.2 / PRJ-020..028). */
class CreateProjectTest extends ProjectTestCase
{
    use RefreshDatabase;

    public function test_owner_can_open_the_projects_screen(): void
    {
        [$owner] = $this->owner();

        $this->actingAs($owner)->get(route('projects.index'))
            ->assertOk()
            ->assertSee('Add Project');
    }

    public function test_owner_can_create_a_project_and_is_routed_to_it(): void
    {
        [$owner, $workspace] = $this->owner();

        $response = $this->actingAs($owner)->postJson(route('projects.store'), [
            'name' => 'Website Redesign 2026',
            'identifier' => 'WEB',
            'description' => 'Marketing site refresh',
            'visibility' => 'private',
        ]);

        $response->assertCreated()->assertJsonPath('ok', true);

        $project = $workspace->run(fn () => Project::first());
        $this->assertNotNull($project);
        $this->assertSame('Website Redesign 2026', $project->name);
        $this->assertSame('WEB', $project->identifier);
        $this->assertSame('private', $project->visibility);
        $this->assertSame($owner->id, $project->created_by);
        $this->assertSame($workspace->id, $project->tenant_id);
        $response->assertJsonPath('redirect', route('projects.show', $project->id));

        // Creator is seeded as a project admin (PRJ-031 access).
        $this->assertTrue($workspace->run(fn () => ProjectMember::where('project_id', $project->id)
            ->where('user_id', $owner->id)->where('role', 'admin')->exists()));
    }

    public function test_name_and_identifier_are_required(): void
    {
        [$owner] = $this->owner();

        $this->actingAs($owner)->postJson(route('projects.store'), [
            'name' => '   ', 'identifier' => '', 'visibility' => 'public',
        ])->assertStatus(422)->assertJsonValidationErrors(['name', 'identifier']);
    }

    public function test_identifier_auto_derives_from_name_when_blank(): void
    {
        [$owner, $workspace] = $this->owner();

        $this->actingAs($owner)->postJson(route('projects.store'), [
            'name' => 'Website Redesign 2026', 'visibility' => 'public',
        ])->assertCreated();

        // Uppercase alphanumerics, capped at the configured length (PRJ-023).
        $this->assertSame('WEBSITERED', $workspace->run(fn () => Project::first()->identifier));
    }

    public function test_duplicate_identifier_in_same_workspace_is_rejected(): void
    {
        [$owner, $workspace] = $this->owner();
        $this->makeProject($owner, $workspace, ['identifier' => 'WEB']);

        $this->actingAs($owner)->postJson(route('projects.store'), [
            'name' => 'Another', 'identifier' => 'WEB', 'visibility' => 'public',
        ])->assertStatus(422)->assertJsonValidationErrors('identifier');

        $this->assertSame(1, $workspace->run(fn () => Project::where('identifier', 'WEB')->count()));
    }

    public function test_same_identifier_is_allowed_in_a_different_workspace(): void
    {
        [$ownerA, $wsA] = $this->owner('ws-a');
        [$ownerB, $wsB] = $this->owner('ws-b');

        $this->makeProject($ownerA, $wsA, ['identifier' => 'WEB']);

        $this->actingAs($ownerB)->postJson(route('projects.store'), [
            'name' => 'Web B', 'identifier' => 'WEB', 'visibility' => 'public',
        ])->assertCreated();

        $this->assertTrue($wsB->run(fn () => Project::where('identifier', 'WEB')->exists()));
    }

    public function test_lead_must_be_a_workspace_member(): void
    {
        [$owner, $workspace] = $this->owner();
        $member = $this->member($workspace, 'member', 'lead@example.com');

        // Valid member lead is accepted and seeded as a project member.
        $this->actingAs($owner)->postJson(route('projects.store'), [
            'name' => 'Led Project', 'identifier' => 'LED', 'visibility' => 'public',
            'lead_user_id' => $member->id,
        ])->assertCreated();

        $project = $workspace->run(fn () => Project::first());
        $this->assertSame($member->id, $project->lead_user_id);
        $this->assertTrue($workspace->run(fn () => ProjectMember::where('project_id', $project->id)
            ->where('user_id', $member->id)->exists()));

        // A user who is not a member of the workspace is rejected.
        $stranger = $this->member($this->owner('other-ws')[1], 'member', 'stranger@example.com');
        $this->actingAs($owner)->postJson(route('projects.store'), [
            'name' => 'Bad Lead', 'identifier' => 'BAD', 'visibility' => 'public',
            'lead_user_id' => $stranger->id,
        ])->assertStatus(422)->assertJsonValidationErrors('lead_user_id');
    }

    public function test_optional_cover_uploads_and_persists(): void
    {
        Storage::fake('public');
        [$owner, $workspace] = $this->owner();

        $this->actingAs($owner)->post(route('projects.store'), [
            'name' => 'Covered', 'identifier' => 'COV', 'visibility' => 'public',
            'cover' => UploadedFile::fake()->image('cover.png', 800, 400),
        ], ['Accept' => 'application/json'])->assertCreated();

        $project = $workspace->run(fn () => Project::first());
        $this->assertNotNull($project->cover_url);
    }

    public function test_invalid_cover_type_is_rejected(): void
    {
        Storage::fake('public');
        [$owner] = $this->owner();

        $this->actingAs($owner)->post(route('projects.store'), [
            'name' => 'Bad Cover', 'identifier' => 'BC', 'visibility' => 'public',
            'cover' => UploadedFile::fake()->create('notes.pdf', 40, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('cover');
    }

    public function test_viewer_and_guest_cannot_create(): void
    {
        [, $workspace] = $this->owner();

        foreach (['viewer', 'guest'] as $role) {
            $user = $this->member($workspace, $role, "{$role}@example.com");
            $this->actingAs($user)->postJson(route('projects.store'), [
                'name' => 'Nope', 'identifier' => strtoupper($role), 'visibility' => 'public',
            ])->assertForbidden();
        }

        $this->assertSame(0, $workspace->run(fn () => Project::count()));
    }

    public function test_unlimited_projects_can_be_created(): void
    {
        [$owner, $workspace] = $this->owner();

        for ($i = 1; $i <= 30; $i++) {
            $this->makeProject($owner, $workspace, ['name' => "Project {$i}", 'identifier' => 'P'.$i]);
        }

        $this->assertSame(30, $workspace->run(fn () => Project::count()));
    }
}
