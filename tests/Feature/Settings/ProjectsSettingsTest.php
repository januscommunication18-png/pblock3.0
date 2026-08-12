<?php

namespace Tests\Feature\Settings;

use App\Models\ProjectLabel;
use App\Models\ProjectPriority;
use App\Models\ProjectState;
use App\Models\WorkspaceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;

/** Features > Projects (spec §6 / SET-PROJ-*). */
class ProjectsSettingsTest extends SettingsTestCase
{
    use RefreshDatabase;

    public function test_default_states_are_seeded_once_on_first_visit(): void
    {
        [$owner, $workspace] = $this->owner();

        $this->actingAs($owner)->get(route('settings.projects'))->assertOk();
        // Visiting again must not re-seed.
        $this->actingAs($owner)->get(route('settings.projects'))->assertOk();

        $states = $workspace->run(fn () => ProjectState::orderBy('position')->get());
        $this->assertCount(6, $states);
        $this->assertSame('Draft', $states->first()->name);
        $this->assertTrue($states->firstWhere('name', 'Draft')->is_default);
    }

    public function test_toggle_project_states_persists(): void
    {
        [$owner, $workspace] = $this->owner();

        $this->actingAs($owner)->postJson(route('settings.projects.toggle'), ['enabled' => false])
            ->assertOk()->assertJsonPath('enabled', false);

        $this->assertFalse($workspace->run(fn () => WorkspaceSettings::first()->project_states_enabled));
    }

    public function test_add_state(): void
    {
        [$owner, $workspace] = $this->owner();

        $this->actingAs($owner)->postJson(route('settings.projects.states.store'), [
            'name' => 'Review', 'color' => '#2563EB', 'group' => 'active', 'description' => 'In review',
        ])->assertOk();

        $this->assertTrue($workspace->run(fn () => ProjectState::where('name', 'Review')->exists()));
    }

    public function test_deleting_the_default_state_reassigns_the_default(): void
    {
        [$owner, $workspace] = $this->owner();
        $this->actingAs($owner)->get(route('settings.projects')); // seed

        $draft = $workspace->run(fn () => ProjectState::where('name', 'Draft')->first());
        $this->actingAs($owner)->deleteJson(route('settings.projects.states.destroy', ['state' => $draft->id]))->assertOk();

        $this->assertFalse($workspace->run(fn () => ProjectState::where('name', 'Draft')->exists()));
        $this->assertSame(1, $workspace->run(fn () => ProjectState::where('is_default', true)->count()));
    }

    public function test_cannot_delete_the_last_remaining_state(): void
    {
        [$owner, $workspace] = $this->owner();
        $this->actingAs($owner)->get(route('settings.projects')); // seed 6

        // Reduce to a single state directly, then the endpoint must refuse to delete it.
        $last = $workspace->run(function () {
            ProjectState::where('name', '!=', 'Draft')->delete();

            return ProjectState::first();
        });

        $this->actingAs($owner)->deleteJson(route('settings.projects.states.destroy', ['state' => $last->id]))
            ->assertStatus(422);
        $this->assertSame(1, $workspace->run(fn () => ProjectState::count()));
    }

    public function test_label_crud(): void
    {
        [$owner, $workspace] = $this->owner();

        $this->actingAs($owner)->postJson(route('settings.projects.labels.store'), [
            'name' => 'Backend', 'color' => '#7C3AED',
        ])->assertOk()->assertJsonPath('labels.0.name', 'Backend');

        $label = $workspace->run(fn () => ProjectLabel::first());

        $this->actingAs($owner)->patchJson(route('settings.projects.labels.update', ['label' => $label->id]), [
            'name' => 'Backend API', 'color' => '#2563EB',
        ])->assertOk();
        $this->assertTrue($workspace->run(fn () => ProjectLabel::where('name', 'Backend API')->exists()));

        $this->actingAs($owner)->deleteJson(route('settings.projects.labels.destroy', ['label' => $label->id]))->assertOk();
        $this->assertSame(0, $workspace->run(fn () => ProjectLabel::count()));
    }

    public function test_invalid_color_is_rejected(): void
    {
        [$owner] = $this->owner();

        $this->actingAs($owner)->postJson(route('settings.projects.labels.store'), [
            'name' => 'Bad', 'color' => 'blue',
        ])->assertStatus(422)->assertJsonValidationErrors('color');
    }

    public function test_labels_are_isolated_between_workspaces(): void
    {
        [$ownerA, $wsA] = $this->owner('ws-a');
        [$ownerB, $wsB] = $this->owner('ws-b');

        // Create a label in workspace B.
        $this->actingAs($ownerB)->postJson(route('settings.projects.labels.store'), [
            'name' => 'B-only', 'color' => '#DC2626',
        ])->assertOk();
        $labelB = $wsB->run(fn () => ProjectLabel::first());

        // Owner A (different active workspace) cannot see or delete B's label — 404 via tenant scope.
        $this->actingAs($ownerA)->deleteJson(route('settings.projects.labels.destroy', ['label' => $labelB->id]))
            ->assertNotFound();
        $this->assertTrue($wsB->run(fn () => ProjectLabel::whereKey($labelB->id)->exists()));
    }

    public function test_default_priorities_are_seeded_on_first_visit(): void
    {
        [$owner, $workspace] = $this->owner();

        $this->actingAs($owner)->get(route('settings.projects'))
            ->assertOk()
            ->assertSee('Urgent')->assertSee('High')->assertSee('Medium')->assertSee('Low')->assertSee('None');

        $priorities = $workspace->run(fn () => ProjectPriority::orderBy('position')->pluck('name')->all());
        $this->assertSame(['Urgent', 'High', 'Medium', 'Low', 'None'], $priorities);
    }

    public function test_priority_can_be_renamed(): void
    {
        [$owner, $workspace] = $this->owner();
        $this->actingAs($owner)->get(route('settings.projects'))->assertOk(); // seeds priorities

        $urgent = $workspace->run(fn () => ProjectPriority::where('name', 'Urgent')->first());

        $this->actingAs($owner)->patchJson(route('settings.projects.priorities.update', ['priority' => $urgent->id]), [
            'name' => 'Critical', 'color' => '#B91C1C',
        ])->assertOk()->assertJsonPath('priorities.0.name', 'Critical');
    }

    public function test_priority_can_be_added_and_deleted(): void
    {
        [$owner, $workspace] = $this->owner();
        $this->actingAs($owner)->get(route('settings.projects'))->assertOk();

        $this->actingAs($owner)->postJson(route('settings.projects.priorities.store'), [
            'name' => 'Blocker', 'color' => '#7C3AED',
        ])->assertOk();

        $blocker = $workspace->run(fn () => ProjectPriority::where('name', 'Blocker')->first());
        $this->assertNotNull($blocker);

        $this->actingAs($owner)->deleteJson(route('settings.projects.priorities.destroy', ['priority' => $blocker->id]))
            ->assertOk();
        $this->assertFalse($workspace->run(fn () => ProjectPriority::whereKey($blocker->id)->exists()));
    }
}
