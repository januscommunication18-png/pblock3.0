<?php

namespace Tests\Feature\Project;

use App\Models\Cycle;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkItemActivity;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Cycles (sprints).
 * Source: ProjectBlock 3.0 — Cycles (Sprint) Requirements; §18's acceptance criteria.
 */
class CycleTest extends ProjectTestCase
{
    use RefreshDatabase;

    // ================= §3: the project-level feature switch =================

    public function test_the_tab_and_the_work_item_property_appear_only_when_cycles_is_on(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);

        // §3.2.4: off by default — no tab, and the page itself is not reachable.
        $this->actingAs($owner)->get(route('projects.cycles', $project))->assertNotFound();
        $this->assertFalse($this->tabKeys($owner, $project)->contains('cycles'));
        $this->assertFalse($this->workItemBootstrap($owner, $project)['cyclesEnabled']);

        $this->enable($owner, $project, 'cycles');

        // §3.2.3 / §18: enabling it exposes the tab, the page and the work item property.
        $this->actingAs($owner)->get(route('projects.cycles', $project))->assertOk();
        $this->assertTrue($this->tabKeys($owner, $project)->contains('cycles'));
        $this->assertTrue($this->workItemBootstrap($owner, $project)['cyclesEnabled']);
    }

    public function test_disabling_cycles_keeps_every_cycle_and_association(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $this->enable($owner, $project, 'cycles');

        $cycle = $this->cycle($ws, $project, 'Sprint 08');
        $item = $this->workItem($owner, $project, 'Ship the banner');
        $this->assign($owner, $project, $item, $cycle->id)->assertOk();

        $this->actingAs($owner)->postJson(route('projects.settings.features.toggle', $project), [
            'feature' => 'cycles', 'enabled' => false,
        ])->assertOk()->assertJsonPath('features.cycles', false);

        // §3.2.4: nothing is deleted. The tab is gone, the rows are not.
        $this->actingAs($owner)->get(route('projects.cycles', $project))->assertNotFound();
        $this->assertTrue($ws->run(fn () => Cycle::whereKey($cycle->id)->exists()));
        $this->assertSame($cycle->id, $ws->run(fn () => WorkItem::find($item->id)->cycle_id));

        // …and no NEW assignment is accepted while it is off.
        $other = $this->workItem($owner, $project, 'Second item');
        $this->assign($owner, $project, $other, $cycle->id)->assertStatus(422);

        // §18: re-enabling restores the lot.
        $this->enable($owner, $project, 'cycles');
        $this->actingAs($owner)->get(route('projects.cycles', $project))->assertOk();
        $this->assertSame($cycle->id, $ws->run(fn () => WorkItem::find($item->id)->cycle_id));
    }

    public function test_parallel_cycles_needs_cycles_on_first_and_switches_off_with_it(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);

        // §3.3.1: meaningless on its own, and refused server-side — not merely disabled in
        // the UI, which a hand-rolled POST would walk straight past.
        $this->actingAs($owner)->postJson(route('projects.settings.features.toggle', $project), [
            'feature' => 'parallel_cycles', 'enabled' => true,
        ])->assertStatus(422);

        $this->enable($owner, $project, 'cycles');
        $this->enable($owner, $project, 'parallel_cycles');

        // Turning the prerequisite off cannot leave the dependent stored as on.
        $this->actingAs($owner)->postJson(route('projects.settings.features.toggle', $project), [
            'feature' => 'cycles', 'enabled' => false,
        ])->assertOk()->assertJsonPath('features.parallel_cycles', false);
    }

    public function test_parallel_cycles_is_refused_when_the_plan_does_not_include_it(): void
    {
        // §13: the backend must enforce the entitlement, not only the frontend.
        config()->set('projects.entitlements.parallel_cycles', false);

        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $this->enable($owner, $project, 'cycles');

        $this->actingAs($owner)->postJson(route('projects.settings.features.toggle', $project), [
            'feature' => 'parallel_cycles', 'enabled' => true,
        ])->assertStatus(422);

        $this->actingAs($owner)->get(route('projects.settings', ['project' => $project->id, 'section' => 'features']))
            ->assertOk()
            ->assertViewHas('bootstrap', fn ($b) => $b['catalog']['parallel_cycles']['entitled'] === false);
    }

    // ================= §6 / §9: creating cycles =================

    public function test_a_cycle_is_created_with_a_derived_status_and_audit_metadata(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $this->enable($owner, $project, 'cycles');

        $this->actingAs($owner)->postJson(route('projects.cycles.store', $project), [
            'name' => 'Sprint 12',
            'description' => 'Checkout rework',
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addDays(13)->toDateString(),
        ])->assertStatus(201)
            // §6.3: the state comes from the dates, so a cycle spanning today is Active.
            ->assertJsonPath('cycle.status', 'active')
            ->assertJsonPath('cycle.name', 'Sprint 12');

        $cycle = $ws->run(fn () => Cycle::first());
        $this->assertSame($project->id, $cycle->project_id);
        $this->assertSame($owner->id, $cycle->created_by);
        $this->assertSame($ws->id, $cycle->tenant_id);
    }

    public function test_the_end_date_cannot_fall_before_the_start_date(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $this->enable($owner, $project, 'cycles');

        $this->actingAs($owner)->postJson(route('projects.cycles.store', $project), [
            'name' => 'Backwards', 'start_date' => now()->addDays(5)->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors('end_date');
    }

    public function test_overlapping_ranges_are_rejected_until_parallel_cycles_is_on(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $this->enable($owner, $project, 'cycles');

        $this->cycle($ws, $project, 'Product', now()->toDateString(), now()->addDays(13)->toDateString());

        $overlapping = [
            'name' => 'API', 'start_date' => now()->addDays(4)->toDateString(),
            'end_date' => now()->addDays(18)->toDateString(),
        ];

        // §3.3.2 / §18: one cycle at a time means no overlapping ranges.
        $this->actingAs($owner)->postJson(route('projects.cycles.store', $project), $overlapping)
            ->assertStatus(422)->assertJsonValidationErrors('start_date');

        $this->enable($owner, $project, 'parallel_cycles');

        // §3.3.3 / §11: with the feature on, overlapping is the entire point.
        $this->actingAs($owner)->postJson(route('projects.cycles.store', $project), $overlapping)
            ->assertStatus(201);
    }

    public function test_switching_parallel_cycles_off_leaves_existing_overlaps_alone(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $this->enable($owner, $project, 'cycles');
        $this->enable($owner, $project, 'parallel_cycles');

        $this->cycle($ws, $project, 'Product', now()->toDateString(), now()->addDays(13)->toDateString());
        $this->cycle($ws, $project, 'API', now()->addDays(4)->toDateString(), now()->addDays(18)->toDateString());

        $this->actingAs($owner)->postJson(route('projects.settings.features.toggle', $project), [
            'feature' => 'parallel_cycles', 'enabled' => false,
        ])->assertOk();

        // §3.3.4: the existing overlap survives untouched…
        $this->assertSame(2, $ws->run(fn () => Cycle::forProject($project->id)->count()));

        // …but no new one may be created from here on.
        $this->actingAs($owner)->postJson(route('projects.cycles.store', $project), [
            'name' => 'Third', 'start_date' => now()->addDays(2)->toDateString(),
            'end_date' => now()->addDays(9)->toDateString(),
        ])->assertStatus(422);
    }

    public function test_a_cycle_never_conflicts_with_itself_when_edited(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $this->enable($owner, $project, 'cycles');
        $cycle = $this->cycle($ws, $project, 'Sprint 1');

        $this->actingAs($owner)->patchJson(route('projects.cycles.update', ['project' => $project->id, 'cycle' => $cycle->id]), [
            'name' => 'Sprint 1 — extended',
            'start_date' => $cycle->start_date->toDateString(),
            'end_date' => $cycle->end_date->addWeek()->toDateString(),
        ])->assertOk()->assertJsonPath('cycle.name', 'Sprint 1 — extended');
    }

    // ================= §5: the landing page =================

    public function test_the_landing_page_groups_cycles_by_their_derived_status(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $this->enable($owner, $project, 'cycles');

        $this->cycle($ws, $project, 'Finished', now()->subDays(20)->toDateString(), now()->subDays(6)->toDateString());
        $this->cycle($ws, $project, 'Running', now()->subDays(2)->toDateString(), now()->addDays(5)->toDateString());
        $this->cycle($ws, $project, 'Planned', now()->addDays(10)->toDateString(), now()->addDays(20)->toDateString());

        $cycles = collect($this->actingAs($owner)->get(route('projects.cycles', $project))
            ->assertOk()->viewData('bootstrap')['cycles']);

        $this->assertSame('completed', $cycles->firstWhere('name', 'Finished')['status']);
        $this->assertSame('active', $cycles->firstWhere('name', 'Running')['status']);
        $this->assertSame('upcoming', $cycles->firstWhere('name', 'Planned')['status']);
    }

    public function test_the_detail_page_reports_progress_and_its_work_items(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $this->enable($owner, $project, 'cycles');
        $cycle = $this->cycle($ws, $project, 'Sprint 08');

        $done = $this->workItem($owner, $project, 'Finished work');
        $open = $this->workItem($owner, $project, 'Outstanding work');
        $this->assign($owner, $project, $done, $cycle->id);
        $this->assign($owner, $project, $open, $cycle->id);
        $this->moveToGroup($owner, $project, $done, 'completed');

        $bootstrap = $this->actingAs($owner)
            ->get(route('projects.cycles.show', ['project' => $project->id, 'cycle' => $cycle->id]))
            ->assertOk()->viewData('bootstrap');

        $this->assertSame($cycle->id, $bootstrap['pageCycleId']);
        $this->assertCount(2, $bootstrap['items']);

        // §5.1: 1 of 2 done — cancelled work would not count towards this either way.
        $card = collect($bootstrap['cycles'])->firstWhere('id', $cycle->id);
        $this->assertSame(2, $card['breakdown']['scope']);
        $this->assertSame(1, $card['breakdown']['completed']);
        $this->assertSame(50, $card['breakdown']['progress']);
    }

    public function test_the_cycle_grid_gets_the_same_row_payload_as_the_work_item_list(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $this->enable($owner, $project, 'cycles');
        $cycle = $this->cycle($ws, $project, 'Sprint 08');

        $item = $this->workItem($owner, $project, 'Planned work');
        $this->assign($owner, $project, $item, $cycle->id)->assertOk();

        $row = $this->actingAs($owner)
            ->get(route('projects.cycles.show', ['project' => $project->id, 'cycle' => $cycle->id]))
            ->assertOk()->viewData('bootstrap')['items'][0];

        // §7.2: the cycle's list IS the work item list's grid, so it has to be handed the
        // same row fields — a missing one silently drops a chip from every row here while
        // the work item list keeps showing it.
        foreach ([
            'id', 'identifier', 'title', 'state', 'group', 'priority',
            'start_date', 'due_date', 'blocked_by_count', 'assignees', 'labels',
        ] as $field) {
            $this->assertArrayHasKey($field, $row);
        }

        // …and the project's states travel with it, so the grid groups in the project's own
        // state order rather than inventing one.
        $states = $this->actingAs($owner)
            ->get(route('projects.cycles.show', ['project' => $project->id, 'cycle' => $cycle->id]))
            ->viewData('bootstrap')['states'];
        $this->assertNotEmpty($states);
        $this->assertArrayHasKey('color', $states[0]);
    }

    // ================= §8: the work item Cycle property =================

    public function test_choosing_another_cycle_moves_the_work_item_and_records_the_move(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $this->enable($owner, $project, 'cycles');
        $this->enable($owner, $project, 'parallel_cycles');

        $eight = $this->cycle($ws, $project, 'Sprint 08');
        $nine = $this->cycle($ws, $project, 'Sprint 09');
        $item = $this->workItem($owner, $project, 'Update homepage banner');

        $this->assign($owner, $project, $item, $eight->id)->assertOk()->assertJsonPath('item.cycle.name', 'Sprint 08');
        $this->assign($owner, $project, $item, $nine->id)->assertOk()->assertJsonPath('item.cycle.name', 'Sprint 09');

        // §8.3.1/§8.3.3: one cycle at a time, and the second choice MOVED it rather than
        // adding a second membership — which the schema makes unrepresentable.
        $fresh = $ws->run(fn () => WorkItem::find($item->id));
        $this->assertSame($nine->id, $fresh->cycle_id);
        $this->assertSame($owner->id, $fresh->cycle_assigned_by);
        $this->assertNotNull($fresh->cycle_assigned_at);

        // §8.4: the feed reads "changed cycle: Sprint 08 → Sprint 09", with names frozen at
        // write time rather than re-resolved on read.
        $moves = $ws->run(fn () => WorkItemActivity::where('work_item_id', $item->id)
            ->where('field', 'cycle')->orderBy('id')->get());
        $this->assertCount(2, $moves);
        $this->assertNull($moves[0]->meta['old_label']);
        $this->assertSame('Sprint 08', $moves[0]->meta['new_label']);
        $this->assertSame('Sprint 08', $moves[1]->meta['old_label']);
        $this->assertSame('Sprint 09', $moves[1]->meta['new_label']);

        // §8.3.7: removing the cycle clears the relationship, not the work item.
        $this->assign($owner, $project, $item, null)->assertOk();
        $fresh = $ws->run(fn () => WorkItem::find($item->id));
        $this->assertNull($fresh->cycle_id);
        $this->assertNull($fresh->cycle_assigned_by);
        $this->assertSame('Update homepage banner', $fresh->title);
    }

    public function test_a_cycle_from_another_project_cannot_be_assigned(): void
    {
        [$owner, $ws] = $this->owner();
        $mine = $this->makeProject($owner, $ws, ['identifier' => 'mine']);
        $theirs = $this->makeProject($owner, $ws, ['name' => 'Other', 'identifier' => 'other']);
        $this->enable($owner, $mine, 'cycles');
        $this->enable($owner, $theirs, 'cycles');

        $foreign = $this->cycle($ws, $theirs, 'Their sprint');
        $item = $this->workItem($owner, $mine, 'My work');

        // §8.3.6: cycle assignments are project-scoped, enforced in validation rather than
        // left to the picker to get right.
        $this->assign($owner, $mine, $item, $foreign->id)
            ->assertStatus(422)->assertJsonValidationErrors('cycle_id');
    }

    public function test_a_completed_cycle_does_not_accept_new_work(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $this->enable($owner, $project, 'cycles');

        $over = $this->cycle($ws, $project, 'Sprint 01', now()->subDays(20)->toDateString(), now()->subDays(6)->toDateString());
        $item = $this->workItem($owner, $project, 'Late arrival');

        // §8.3.5 — and the picker never offers it either.
        $this->assign($owner, $project, $item, $over->id)
            ->assertStatus(422)->assertJsonValidationErrors('cycle_id');

        $this->assertSame([], array_column($this->workItemBootstrap($owner, $project)['cycles'], 'name'));
    }

    public function test_an_item_already_in_a_finished_cycle_can_still_be_edited(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $this->enable($owner, $project, 'cycles');

        $cycle = $this->cycle($ws, $project, 'Sprint 01');
        $item = $this->workItem($owner, $project, 'Carried over');
        $this->assign($owner, $project, $item, $cycle->id)->assertOk();

        // The cycle ends. Echoing the unchanged cycle back alongside another edit must not be
        // read as a new assignment into a finished cycle.
        $ws->run(fn () => Cycle::whereKey($cycle->id)->update([
            'start_date' => now()->subDays(20)->toDateString(),
            'end_date' => now()->subDays(6)->toDateString(),
        ]));

        $this->actingAs($owner)->patchJson(
            route('projects.work-items.update', ['project' => $project->id, 'workItem' => $item->id]),
            ['title' => 'Carried over, renamed', 'cycle_id' => $cycle->id],
        )->assertOk();
    }

    // ================= §7.3 / §10: cycle scope =================

    public function test_work_items_can_be_added_to_and_removed_from_a_cycle(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $this->enable($owner, $project, 'cycles');
        $cycle = $this->cycle($ws, $project, 'Sprint 08');

        $one = $this->workItem($owner, $project, 'First');
        $two = $this->workItem($owner, $project, 'Second');

        $this->actingAs($owner)->postJson(
            route('projects.cycles.items.store', ['project' => $project->id, 'cycle' => $cycle->id]),
            ['work_item_ids' => [$one->id, $two->id]],
        )->assertOk()->assertJsonCount(2, 'items');

        $this->actingAs($owner)->deleteJson(route('projects.cycles.items.destroy', [
            'project' => $project->id, 'cycle' => $cycle->id, 'workItem' => $one->id,
        ]))->assertOk()->assertJsonCount(1, 'items');

        $this->assertNull($ws->run(fn () => WorkItem::find($one->id)->cycle_id));
        $this->assertSame($cycle->id, $ws->run(fn () => WorkItem::find($two->id)->cycle_id));
    }

    public function test_the_search_endpoint_never_offers_another_projects_work(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'mine']);
        $other = $this->makeProject($owner, $ws, ['name' => 'Other', 'identifier' => 'other']);
        $this->enable($owner, $project, 'cycles');

        $cycle = $this->cycle($ws, $project, 'Sprint 08');
        $this->workItem($owner, $project, 'Findable work');
        $this->workItem($owner, $other, 'Findable work');

        $found = $this->actingAs($owner)->getJson(route('projects.cycles.search', [
            'project' => $project->id, 'cycle' => $cycle->id, 'q' => 'Findable',
        ]))->assertOk()->json('items');

        $this->assertCount(1, $found);
    }

    public function test_transfer_moves_only_unfinished_work_and_preserves_the_items(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $this->enable($owner, $project, 'cycles');

        $over = $this->cycle($ws, $project, 'Sprint 01', now()->subDays(20)->toDateString(), now()->subDays(6)->toDateString());
        $next = $this->cycle($ws, $project, 'Sprint 02');

        $done = $this->workItem($owner, $project, 'Shipped');
        $open = $this->workItem($owner, $project, 'Still going');
        $ws->run(fn () => WorkItem::whereIn('id', [$done->id, $open->id])->update(['cycle_id' => $over->id]));
        $this->moveToGroup($owner, $project, $done, 'completed');

        $this->actingAs($owner)->postJson(
            route('projects.cycles.transfer', ['project' => $project->id, 'cycle' => $over->id]),
            ['to_cycle_id' => $next->id],
        )->assertOk();

        // §10: unfinished work moves, finished work stays as the record of what the cycle did.
        $this->assertSame($next->id, $ws->run(fn () => WorkItem::find($open->id)->cycle_id));
        $this->assertSame($over->id, $ws->run(fn () => WorkItem::find($done->id)->cycle_id));

        // A move, not a copy — and nothing else about the item changed.
        $moved = $ws->run(fn () => WorkItem::find($open->id));
        $this->assertSame('Still going', $moved->title);
        $this->assertSame(1, $ws->run(fn () => WorkItemActivity::where('work_item_id', $open->id)
            ->where('field', 'cycle')->where('new_value', (string) $next->id)->count()));
    }

    public function test_transfer_refuses_a_destination_that_has_already_ended(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $this->enable($owner, $project, 'cycles');
        $this->enable($owner, $project, 'parallel_cycles');

        $over = $this->cycle($ws, $project, 'Sprint 01', now()->subDays(20)->toDateString(), now()->subDays(6)->toDateString());
        $alsoOver = $this->cycle($ws, $project, 'Sprint 00', now()->subDays(40)->toDateString(), now()->subDays(26)->toDateString());

        $this->actingAs($owner)->postJson(
            route('projects.cycles.transfer', ['project' => $project->id, 'cycle' => $over->id]),
            ['to_cycle_id' => $alsoOver->id],
        )->assertStatus(422);
    }

    // ================= §12: permissions =================

    public function test_a_contributor_may_plan_work_but_only_an_admin_may_delete_a_cycle(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $this->enable($owner, $project, 'cycles');
        $cycle = $this->cycle($ws, $project, 'Sprint 08');

        $contributor = $this->projectMember($ws, $project, 'contributor@example.com', ProjectMember::ROLE_CONTRIBUTOR);
        $commenter = $this->projectMember($ws, $project, 'commenter@example.com', ProjectMember::ROLE_COMMENTER);

        // §12: Contributors create and edit…
        $this->actingAs($contributor)->postJson(route('projects.cycles.store', $project), [
            'name' => 'Contributor cycle', 'start_date' => now()->addMonth()->toDateString(),
            'end_date' => now()->addMonth()->addWeek()->toDateString(),
        ])->assertStatus(201);

        // …but Delete is reserved for the project admin.
        $this->actingAs($contributor)->deleteJson(route('projects.cycles.destroy', [
            'project' => $project->id, 'cycle' => $cycle->id,
        ]))->assertStatus(403);

        // Commenters may look and no more.
        $this->actingAs($commenter)->get(route('projects.cycles', $project))->assertOk();
        $this->actingAs($commenter)->postJson(route('projects.cycles.store', $project), [
            'name' => 'Nope', 'start_date' => now()->toDateString(), 'end_date' => now()->addWeek()->toDateString(),
        ])->assertStatus(403);
    }

    public function test_a_cycle_of_a_project_in_another_workspace_is_not_found(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $this->enable($owner, $project, 'cycles');

        [$outsider] = $this->owner('other-co');

        // 404 rather than 403: the response must not confirm the project or cycle exists.
        $this->actingAs($outsider)->get(route('projects.cycles', $project))->assertNotFound();
    }

    public function test_deleting_a_cycle_keeps_its_work_items(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $this->enable($owner, $project, 'cycles');
        $cycle = $this->cycle($ws, $project, 'Sprint 08');
        $item = $this->workItem($owner, $project, 'Survivor');
        $this->assign($owner, $project, $item, $cycle->id)->assertOk();

        $this->actingAs($owner)->deleteJson(route('projects.cycles.destroy', [
            'project' => $project->id, 'cycle' => $cycle->id,
        ]))->assertOk();

        // §8.3.7 has to hold even when the removal is the cycle's own deletion.
        $survivor = $ws->run(fn () => WorkItem::find($item->id));
        $this->assertNotNull($survivor);
        $this->assertNull($survivor->cycle_id);
    }

    // ================= helpers =================

    /** Switch a project feature on through the real endpoint, as an admin would. */
    private function enable(User $actor, Project $project, string $feature): void
    {
        $this->actingAs($actor)->postJson(route('projects.settings.features.toggle', $project), [
            'feature' => $feature, 'enabled' => true,
        ])->assertOk()->assertJsonPath("features.{$feature}", true);

        $project->refresh();
    }

    /** A cycle created directly, so a test can place it in the past or overlap deliberately. */
    private function cycle(Workspace $ws, Project $project, string $name, ?string $start = null, ?string $end = null): Cycle
    {
        return $ws->run(fn () => Cycle::create([
            'project_id' => $project->id,
            'name' => $name,
            'start_date' => $start ?? now()->toDateString(),
            'end_date' => $end ?? now()->addDays(13)->toDateString(),
        ]));
    }

    private function workItem(User $actor, Project $project, string $title): WorkItem
    {
        // Through the screen first, so the project's default states exist.
        $this->actingAs($actor)->get(route('projects.work-items', $project));

        $id = $this->actingAs($actor)->postJson(route('projects.work-items.store', $project), [
            'title' => $title,
        ])->assertStatus(201)->json('item.id');

        return $project->tenant->run(fn () => WorkItem::find($id));
    }

    /** PATCH the item's Cycle property, the way the picker does. */
    private function assign(User $actor, Project $project, WorkItem $item, ?int $cycleId)
    {
        return $this->actingAs($actor)->patchJson(
            route('projects.work-items.update', ['project' => $project->id, 'workItem' => $item->id]),
            ['cycle_id' => $cycleId],
        );
    }

    /** Move an item into the project's state of a given group (used to finish work). */
    private function moveToGroup(User $actor, Project $project, WorkItem $item, string $group): void
    {
        $state = $project->tenant->run(fn () => $project->states()->where('group', $group)->first());

        $this->actingAs($actor)->patchJson(
            route('projects.work-items.update', ['project' => $project->id, 'workItem' => $item->id]),
            ['state_id' => $state->id],
        )->assertOk();
    }

    private function projectMember(Workspace $ws, Project $project, string $email, string $role): User
    {
        $user = $this->member($ws, 'member', $email);
        $ws->run(fn () => ProjectMember::create([
            'project_id' => $project->id, 'user_id' => $user->id, 'role' => $role,
        ]));

        return $user;
    }

    /** The tab keys the project workspace renders for this user. */
    private function tabKeys(User $actor, Project $project)
    {
        return collect($this->actingAs($actor)->get(route('projects.work-items', $project))
            ->assertOk()->viewData('tabs'))->pluck('key');
    }

    /** @return array<string, mixed> */
    private function workItemBootstrap(User $actor, Project $project): array
    {
        return $this->actingAs($actor)->get(route('projects.work-items', $project))
            ->assertOk()->viewData('bootstrap');
    }
}
