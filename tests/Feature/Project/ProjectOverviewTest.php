<?php

namespace Tests\Feature\Project;

use App\Models\ProjectItemState;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Project → Overview, and the Overview | Milestones segment (Project Overview §1).
 */
class ProjectOverviewTest extends ProjectTestCase
{
    use RefreshDatabase;

    public function test_the_overview_renders_progress_and_properties(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'TESTI']);

        // Seeds the project's states, which the progress bar groups by.
        $this->actingAs($owner)->get(route('projects.work-items', $project));

        $states = $ws->run(fn () => ProjectItemState::where('project_id', $project->id)->get()->keyBy('group'));

        foreach (['backlog' => 3, 'completed' => 1] as $group => $count) {
            for ($i = 0; $i < $count; $i++) {
                $this->actingAs($owner)->postJson(route('projects.work-items.store', $project), [
                    'title' => ucfirst($group)." item {$i}",
                    'state_id' => $states[$group]->id,
                ])->assertStatus(201);
            }
        }

        $response = $this->actingAs($owner)->get(route('projects.overview', $project))->assertOk();

        $response->assertViewHas('progress', function (array $progress) {
            $byGroup = collect($progress['groups'])->keyBy('group');

            // Four items: three backlog, one completed.
            return $progress['total'] === 4
                && $byGroup['backlog']['count'] === 3
                && $byGroup['backlog']['percent'] === 75
                && $byGroup['completed']['count'] === 1
                // Every group is present even at zero, so the breakdown stays legible.
                && $byGroup['unstarted']['count'] === 0
                && count($progress['groups']) === 5;
        });

        // The panel reads columns that already exist, so an unset one says None rather than
        // being absent — a missing row reads as a missing feature.
        $response->assertViewHas('properties', function (array $rows) {
            $labels = array_column($rows, 'label');

            return $labels === ['State', 'Priority', 'Lead', 'Members', 'Labels', 'Start date', 'Due date'];
        });

        $response->assertSee('Progress', false);
    }

    /** An empty project renders a breakdown rather than a broken bar. */
    public function test_a_project_with_no_work_items_still_renders(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'EMPTY']);

        $this->actingAs($owner)->get(route('projects.overview', $project))
            ->assertOk()
            ->assertViewHas('progress', fn (array $p) => $p['total'] === 0 && count($p['groups']) === 5)
            ->assertSee('No work items yet', false);
    }

    /**
     * The segment is the feature, not decoration.
     *
     * Milestones is off by default, so the segment shows Overview alone and the /milestones
     * URL 404s. Switching the feature on adds the segment and opens the URL — one flag decides
     * both, so a link that appears can always be followed.
     */
    public function test_milestones_appears_in_the_segment_only_when_the_feature_is_on(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'TESTI']);

        $this->assertFalse($project->featureEnabled('milestones'), 'milestones should be off by default');

        $html = $this->actingAs($owner)->get(route('projects.overview', $project))->assertOk()->getContent();
        $this->assertStringNotContainsString(route('projects.milestones', $project), $html);

        $this->actingAs($owner)->get(route('projects.milestones', $project))->assertNotFound();

        // …now switch it on.
        $ws->run(function () use ($project) {
            $project->forceFill(['features' => ['milestones' => true] + (array) $project->features])->save();
        });

        $html = $this->actingAs($owner)->get(route('projects.overview', $project))->assertOk()->getContent();
        $this->assertStringContainsString(route('projects.milestones', $project), $html);

        $this->actingAs($owner)->get(route('projects.milestones', $project))
            ->assertOk()
            ->assertSee('Milestones', false);
    }

    /** 404, never 403: an inaccessible project must not be confirmed to exist (spec §12). */
    public function test_the_overview_404s_for_someone_outside_the_project(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'TESTI', 'visibility' => 'private']);
        $outsider = $this->member($ws, 'member', 'outsider@example.com');

        $this->actingAs($outsider)->get(route('projects.overview', $project))->assertNotFound();
    }

    /** The Overview tab is a real tab now, not a Coming Soon placeholder. */
    public function test_the_overview_tab_links_to_the_real_screen(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'TESTI']);

        $html = $this->actingAs($owner)->get(route('projects.work-items', $project))->assertOk()->getContent();

        $this->assertStringContainsString(route('projects.overview', $project), $html);
        $this->assertSame(0, WorkItem::where('project_id', $project->id)->count());
    }
}
