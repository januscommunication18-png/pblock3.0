<?php

namespace Tests\Feature\Project;

use App\Models\Cycle;
use App\Models\Epic;
use App\Models\EpicActivity;
use App\Models\Module;
use App\Models\Project;
use App\Models\ProjectItemState;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkItemActivity;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Epics.
 * Source: ProjectBlock 3.0 — Epic Requirements; §24's acceptance criteria.
 */
class EpicTest extends ProjectTestCase
{
    use RefreshDatabase;

    // ================= §24.1/§24.2: enablement and the tab =================

    public function test_the_tab_and_the_page_appear_only_when_epics_is_on(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'TESTI']);

        // §3: off by default, so the tab is not in the bar. The page itself still answers —
        // see test_switching_epics_off_leaves_the_page_readable_but_read_only for why.
        $tabs = $this->actingAs($owner)->get(route('projects.epics', $project))
            ->assertOk()->viewData('tabs');
        $this->assertNotContains('epics', array_column($tabs, 'key'));

        $this->enableEpics($owner, $project);

        $tabs = $this->actingAs($owner)->get(route('projects.epics', $project))
            ->assertOk()->viewData('tabs');
        $this->assertContains('epics', array_column($tabs, 'key'));
    }

    public function test_switching_epics_off_leaves_the_page_readable_but_read_only(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->enabled($owner, $ws);
        $epic = $this->epic($ws, $project, 'Customer Onboarding Improvement');

        $this->disableEpics($owner, $project);

        // Existing epics stay accessible for historical reference — the page still loads, and
        // still lists them. This is the line that separates Epic from Cycles and Modules,
        // which hide themselves entirely.
        $bootstrap = $this->actingAs($owner)->get(route('projects.epics', $project))
            ->assertOk()->viewData('bootstrap');

        $this->assertSame(['Customer Onboarding Improvement'], array_column($bootstrap['epics'], 'title'));
        $this->assertFalse($bootstrap['featureEnabled']);
        $this->assertFalse($bootstrap['canCreate']);
        $this->assertStringContainsString('Epics are currently disabled for this project', $bootstrap['disabledNotice']);

        // The per-epic URL stays readable too.
        $this->actingAs($owner)->get(route('projects.epics.show', [
            'project' => $project->id, 'epic' => $epic->id,
        ]))->assertOk();

        // …but every write is refused, not merely hidden.
        $this->actingAs($owner)->postJson(route('projects.epics.store', $project), ['title' => 'New'])
            ->assertStatus(403);
        $this->actingAs($owner)->patchJson(route('projects.epics.update', [
            'project' => $project->id, 'epic' => $epic->id,
        ]), ['title' => 'Renamed'])->assertStatus(403);
        $this->actingAs($owner)->postJson(route('projects.epics.archive', [
            'project' => $project->id, 'epic' => $epic->id,
        ]))->assertStatus(403);
        $this->actingAs($owner)->deleteJson(route('projects.epics.destroy', [
            'project' => $project->id, 'epic' => $epic->id,
        ]))->assertStatus(403);

        $this->assertSame('Customer Onboarding Improvement', $ws->run(fn () => Epic::find($epic->id)->title));
    }

    public function test_re_enabling_restores_everything_without_a_migration(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->enabled($owner, $ws);
        $epic = $this->epic($ws, $project, 'Customer Onboarding Improvement');
        $item = $this->workItem($owner, $project, 'Redesign onboarding');
        $this->assign($owner, $project, $item, $epic->id)->assertOk();

        $this->disableEpics($owner, $project);
        $this->enableEpics($owner, $project);

        // Nothing to recreate: the epic, the assignment and the ability to manage both are
        // simply available again.
        $this->actingAs($owner)->patchJson(route('projects.epics.update', [
            'project' => $project->id, 'epic' => $epic->id,
        ]), ['status' => 'in_progress'])->assertOk();

        $this->assertSame($epic->id, $ws->run(fn () => WorkItem::find($item->id)->epic_id));
        $this->assertSame(['Customer Onboarding Improvement'], array_column(
            $this->actingAs($owner)->get(route('projects.work-items', $project))
                ->assertOk()->viewData('bootstrap')['epics'], 'title'
        ));
    }

    public function test_the_epics_screen_renders_its_own_component(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->enabled($owner, $ws);
        $this->epic($ws, $project, 'Improve Student Recruitment');

        // The screen is a Vue root over a "Loading epics…" placeholder, so a missing script
        // leaves the page permanently blank rather than erroring. Asserting the tag is here
        // is the only part of that a PHP test can see.
        $html = $this->actingAs($owner)->get(route('projects.epics', $project))->assertOk()->getContent();

        $this->assertStringContainsString('assets/js/projects/epics.js', $html);
        $this->assertFileExists(public_path('assets/js/projects/epics.js'));
    }

    public function test_disabling_epics_keeps_every_epic_and_assignment(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->enabled($owner, $ws);
        $epic = $this->epic($ws, $project, 'Improve Student Recruitment');
        $item = $this->workItem($owner, $project, 'Redesign admissions page');

        $this->assign($owner, $project, $item, $epic->id)->assertOk();

        // §4: the warning is a real gate — the first attempt is refused with the count.
        $this->actingAs($owner)->postJson(route('projects.settings.features.toggle', $project), [
            'feature' => 'epics', 'enabled' => false,
        ])->assertStatus(409)->assertJsonPath('confirm', true)->assertJsonPath('count', 1);

        $this->assertTrue($project->fresh()->featureEnabled('epics'));

        // Confirmed, it goes through — and §4 deletes nothing.
        $this->actingAs($owner)->postJson(route('projects.settings.features.toggle', $project), [
            'feature' => 'epics', 'enabled' => false, 'confirm' => true,
        ])->assertOk();

        $this->assertTrue($ws->run(fn () => Epic::whereKey($epic->id)->exists()));
        $this->assertSame($epic->id, $ws->run(fn () => WorkItem::find($item->id)->epic_id));

        $this->enableEpics($owner, $project);
        $this->actingAs($owner)->get(route('projects.epics', $project))->assertOk();
    }

    // ================= §24.3/§24.4: create, and one project per epic =================

    public function test_an_epic_needs_only_a_title_and_starts_in_backlog(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->enabled($owner, $ws);

        $epic = $this->actingAs($owner)->postJson(route('projects.epics.store', $project), [
            'title' => '  Improve Student Recruitment  ',
        ])->assertStatus(201)->json('epic');

        // §6: the title is trimmed; §5: Backlog and no priority are the defaults.
        $this->assertSame('Improve Student Recruitment', $epic['title']);
        $this->assertSame('backlog', $epic['status']);
        $this->assertSame('none', $epic['priority']);
        // §24.4: exactly one project, taken from the URL.
        $this->assertSame($project->id, $ws->run(fn () => Epic::find($epic['id'])->project_id));
    }

    public function test_the_epic_id_counts_up_within_the_project_only(): void
    {
        [$owner, $ws] = $this->owner();
        $a = $this->enabled($owner, $ws, 'AAA');
        $b = $this->enabled($owner, $ws, 'BBB', 'Second');

        $first = $this->actingAs($owner)->postJson(route('projects.epics.store', $a), ['title' => 'One'])
            ->assertStatus(201)->json('epic.identifier');
        $second = $this->actingAs($owner)->postJson(route('projects.epics.store', $a), ['title' => 'Two'])
            ->assertStatus(201)->json('epic.identifier');
        $other = $this->actingAs($owner)->postJson(route('projects.epics.store', $b), ['title' => 'Elsewhere'])
            ->assertStatus(201)->json('epic.identifier');

        // §6: unique WITHIN the project — so the second project starts again at 1.
        $this->assertSame([1, 2], [$first, $second]);
        $this->assertSame(1, $other);
    }

    public function test_the_project_cannot_be_changed_from_inside_the_epic_form(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->enabled($owner, $ws, 'MINE');
        $other = $this->makeProject($owner, $ws, ['identifier' => 'OTHER', 'name' => 'Other']);

        // §6: "Project is automatically assigned and cannot be changed to a different Project
        // from within the Epic form" — a payload carrying one is ignored, not honoured.
        $epic = $this->actingAs($owner)->postJson(route('projects.epics.store', $project), [
            'title' => 'Mine', 'project_id' => $other->id,
        ])->assertStatus(201)->json('epic');

        $this->assertSame($project->id, $ws->run(fn () => Epic::find($epic['id'])->project_id));
    }

    public function test_a_blank_title_or_a_target_date_before_the_start_is_rejected(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->enabled($owner, $ws);

        $this->actingAs($owner)->postJson(route('projects.epics.store', $project), ['title' => '   '])
            ->assertStatus(422)->assertJsonValidationErrors('title');

        // §6: the target date cannot be EARLIER than the start — equal is a legal one-day epic.
        $this->actingAs($owner)->postJson(route('projects.epics.store', $project), [
            'title' => 'Backwards',
            'start_date' => now()->addDays(5)->toDateString(),
            'target_date' => now()->addDay()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors('target_date');

        $this->actingAs($owner)->postJson(route('projects.epics.store', $project), [
            'title' => 'One day',
            'start_date' => now()->toDateString(),
            'target_date' => now()->toDateString(),
        ])->assertStatus(201);
    }

    // ================= §24.5-§24.9: independence from module and cycle =================

    public function test_a_work_item_carries_an_epic_a_module_and_a_cycle_at_once(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->enabled($owner, $ws);
        $this->enableFeature($owner, $project, 'modules');
        $this->enableFeature($owner, $project, 'cycles');

        $epic = $this->epic($ws, $project, 'Improve Student Recruitment');
        $module = $ws->run(fn () => Module::create([
            'project_id' => $project->id, 'title' => 'Admissions', 'status' => 'backlog',
        ]));
        $cycle = $ws->run(fn () => Cycle::create([
            'project_id' => $project->id, 'name' => 'Sprint 4',
            'start_date' => now()->toDateString(), 'end_date' => now()->addDays(13)->toDateString(),
        ]));

        $item = $this->workItem($owner, $project, 'Redesign admissions page');

        // §24.6: all three, set independently and in any order.
        $this->assign($owner, $project, $item, $epic->id)->assertOk();
        $this->patchItem($owner, $project, $item, ['module_ids' => [$module->id]])->assertOk();
        $card = $this->patchItem($owner, $project, $item, ['cycle_id' => $cycle->id])->assertOk()->json('item');

        $this->assertSame($epic->id, $card['epic_id']);
        $this->assertSame('Improve Student Recruitment', $card['epic']['title']);
        $this->assertSame(['Admissions'], array_column($card['modules'], 'title'));
        $this->assertSame($cycle->id, $card['cycle_id']);

        // §24.7/§24.16: removing the epic leaves the module and cycle exactly as they were.
        $card = $this->assign($owner, $project, $item, null)->assertOk()->json('item');
        $this->assertNull($card['epic_id']);
        $this->assertSame(['Admissions'], array_column($card['modules'], 'title'));
        $this->assertSame($cycle->id, $card['cycle_id']);
    }

    public function test_an_epic_spans_several_cycles(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->enabled($owner, $ws);
        $this->enableFeature($owner, $project, 'cycles');

        $epic = $this->epic($ws, $project, 'Improve Student Recruitment');
        $one = $ws->run(fn () => Cycle::create([
            'project_id' => $project->id, 'name' => 'Sprint 1',
            'start_date' => now()->toDateString(), 'end_date' => now()->addDays(13)->toDateString(),
        ]));
        $two = $ws->run(fn () => Cycle::create([
            'project_id' => $project->id, 'name' => 'Sprint 2',
            'start_date' => now()->addDays(14)->toDateString(), 'end_date' => now()->addDays(27)->toDateString(),
        ]));

        foreach ([['Research', $one], ['Build', $two]] as [$title, $cycle]) {
            $item = $this->workItem($owner, $project, $title);
            $this->assign($owner, $project, $item, $epic->id)->assertOk();
            $this->patchItem($owner, $project, $item, ['cycle_id' => $cycle->id])->assertOk();
        }

        // §12/§8: the epic reports the spread rather than belonging to one cycle.
        $dist = $this->actingAs($owner)->get(route('projects.epics.show', [
            'project' => $project->id, 'epic' => $epic->id,
        ]))->assertOk()->viewData('bootstrap')['distribution'];

        $this->assertEqualsCanonicalizing(['Sprint 1', 'Sprint 2'], array_column($dist['cycles'], 'name'));
    }

    // ================= §24.10-§24.12: work items and progress =================

    public function test_work_items_are_added_and_removed_without_being_deleted(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->enabled($owner, $ws);
        $epic = $this->epic($ws, $project, 'Improve Student Recruitment');
        $item = $this->workItem($owner, $project, 'Redesign admissions page');

        $url = ['project' => $project->id, 'epic' => $epic->id];

        $this->actingAs($owner)->postJson(route('projects.epics.items.store', $url), [
            'work_item_ids' => [$item->id],
        ])->assertOk()->assertJsonCount(1, 'items');

        // Adding the same item twice is a no-op, not an error.
        $this->actingAs($owner)->postJson(route('projects.epics.items.store', $url), [
            'work_item_ids' => [$item->id],
        ])->assertOk()->assertJsonCount(1, 'items');

        // §24.14: removing the assignment does not delete the work item.
        $this->actingAs($owner)->deleteJson(route('projects.epics.items.destroy',
            $url + ['workItem' => $item->id]))->assertOk()->assertJsonCount(0, 'items');

        $this->assertTrue($ws->run(fn () => WorkItem::whereKey($item->id)->exists()));
        $this->assertNull($ws->run(fn () => WorkItem::find($item->id)->epic_id));
    }

    public function test_progress_counts_completed_work_and_leaves_cancelled_out(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->enabled($owner, $ws);
        $epic = $this->epic($ws, $project, 'Improve Student Recruitment');

        // Two done, one in flight, one cancelled.
        foreach ([['A', 'completed'], ['B', 'completed'], ['C', 'started'], ['D', 'cancelled']] as [$title, $group]) {
            $item = $this->workItem($owner, $project, $title);
            $state = $ws->run(fn () => ProjectItemState::where('project_id', $project->id)
                ->where('group', $group)->first());
            $this->patchItem($owner, $project, $item, ['state_id' => $state->id, 'epic_id' => $epic->id])->assertOk();
        }

        $card = $this->epicCard($owner, $project, $epic);

        // §13: cancelled work leaves the DENOMINATOR entirely, so this is 2 of 3, not 2 of 4.
        $this->assertSame(3, $card['progress']['total']);
        $this->assertSame(2, $card['progress']['completed']);
        $this->assertSame(1, $card['progress']['cancelled']);
        $this->assertSame(67, $card['progress']['percent']);
    }

    public function test_an_epic_with_no_work_items_reports_zero_rather_than_failing(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->enabled($owner, $ws);
        $epic = $this->epic($ws, $project, 'Empty');

        // §13: "0% or 'No Work Items' rather than an error" — never a division by zero.
        $card = $this->epicCard($owner, $project, $epic);
        $this->assertSame(0, $card['progress']['total']);
        $this->assertSame(0, $card['progress']['percent']);
        $this->assertTrue($card['progress']['empty']);
    }

    public function test_the_epic_work_items_tab_mounts_the_work_items_screen(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->enabled($owner, $ws);
        $this->enableFeature($owner, $project, 'modules');
        $epic = $this->epic($ws, $project, 'Improve Student Recruitment');
        $item = $this->workItem($owner, $project, 'Redesign admissions page');
        $this->assign($owner, $project, $item, $epic->id)->assertOk();
        $outside = $this->workItem($owner, $project, 'Unrelated work');

        $bootstrap = $this->actingAs($owner)->get(route('projects.epics.show', [
            'project' => $project->id, 'epic' => $epic->id,
        ]))->assertOk()->viewData('bootstrap');

        // §8's Work Items tab mounts the work items SCREEN, so the payload has to be that
        // screen's payload — chips are only editable if the drawer has every picker option
        // and every endpoint it needs.
        $screen = $bootstrap['workItems'];
        $this->assertTrue($screen['canEdit']);
        $this->assertNotEmpty($screen['states']);
        $this->assertNotEmpty($screen['priorities']);
        $this->assertArrayHasKey('update', $screen['endpoints']);
        $this->assertArrayHasKey('feed', $screen['endpoints']);
        $this->assertArrayHasKey('subtasks', $screen['endpoints']);

        // Narrowed to this epic's items — the tab is the epic's work, not the project's.
        $this->assertSame(['Redesign admissions page'], array_column($screen['items'], 'title'));
        $this->assertNotContains($outside->id, array_column($screen['items'], 'id'));

        // §23: "Add work item" on that tab adds work to THIS epic.
        $this->assertSame(['epic_id' => $epic->id], $screen['seed']);

        // Embedded: the epic header owns the page-level Add button, so the mounted screen
        // does not render a second one beside it.
        $this->assertTrue($screen['embedded']);

        // And the page loads the screen's script, without which the tab renders nothing.
        $html = $this->actingAs($owner)->get(route('projects.epics.show', [
            'project' => $project->id, 'epic' => $epic->id,
        ]))->getContent();
        $this->assertStringContainsString('assets/js/projects/work-items.js', $html);
        // Its own boot must not fire here, or it would mount over the epics root.
        $this->assertStringNotContainsString('data-screen="work-items"', $html);
    }

    // ================= §24.13: sub-tasks =================

    public function test_a_subtask_is_not_counted_separately_from_its_parent(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->enabled($owner, $ws);
        $epic = $this->epic($ws, $project, 'Improve Student Recruitment');

        $parent = $this->workItem($owner, $project, 'Redesign admissions page');
        $child = $this->workItem($owner, $project, 'Write the copy');
        $this->patchItem($owner, $project, $child, ['parent_id' => $parent->id])->assertOk();

        $this->assign($owner, $project, $parent, $epic->id)->assertOk();

        // §13/§10: the sub-task belongs to its parent, not to the epic — counting both would
        // let one piece of work vote twice.
        $card = $this->epicCard($owner, $project, $epic);
        $this->assertSame(1, $card['progress']['total']);
        $this->assertNull($ws->run(fn () => WorkItem::find($child->id)->epic_id));
    }

    // ================= §24.15/§24.16: delete and archive =================

    public function test_deleting_an_epic_keeps_its_work_items_modules_and_cycles(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->enabled($owner, $ws);
        $this->enableFeature($owner, $project, 'modules');

        $epic = $this->epic($ws, $project, 'Improve Student Recruitment');
        $module = $ws->run(fn () => Module::create([
            'project_id' => $project->id, 'title' => 'Admissions', 'status' => 'backlog',
        ]));
        $item = $this->workItem($owner, $project, 'Redesign admissions page');

        $this->assign($owner, $project, $item, $epic->id)->assertOk();
        $this->patchItem($owner, $project, $item, ['module_ids' => [$module->id]])->assertOk();

        $this->actingAs($owner)->deleteJson(route('projects.epics.destroy', [
            'project' => $project->id, 'epic' => $epic->id,
        ]))->assertOk();

        // §16/§24.15/§24.16: the epic record only. The work item survives, keeps its module,
        // and simply loses the epic — which the FK's nullOnDelete is what guarantees.
        $this->assertFalse($ws->run(fn () => Epic::whereKey($epic->id)->exists()));
        $fresh = $ws->run(fn () => WorkItem::with('modules')->find($item->id));
        $this->assertNotNull($fresh);
        $this->assertNull($fresh->epic_id);
        $this->assertSame(['Admissions'], $fresh->modules->pluck('title')->all());
    }

    public function test_an_archived_epic_leaves_the_selector_but_keeps_its_work(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->enabled($owner, $ws);
        $epic = $this->epic($ws, $project, 'Improve Student Recruitment');
        $item = $this->workItem($owner, $project, 'Redesign admissions page');
        $this->assign($owner, $project, $item, $epic->id)->assertOk();

        $this->actingAs($owner)->postJson(route('projects.epics.archive', [
            'project' => $project->id, 'epic' => $epic->id,
        ]))->assertOk();

        // §16: the work item keeps its reference…
        $this->assertSame($epic->id, $ws->run(fn () => WorkItem::find($item->id)->epic_id));

        // …but the epic is out of the picker, and out of the API.
        $bootstrap = $this->actingAs($owner)->get(route('projects.work-items', $project))
            ->assertOk()->viewData('bootstrap');
        $this->assertSame([], $bootstrap['epics']);

        $other = $this->workItem($owner, $project, 'Something else');
        $this->assign($owner, $project, $other, $epic->id)
            ->assertStatus(422)->assertJsonValidationErrors('epic_id');

        // Restoring puts it back.
        $this->actingAs($owner)->postJson(route('projects.epics.restore', [
            'project' => $project->id, 'epic' => $epic->id,
        ]))->assertOk();
        $this->assign($owner, $project, $other, $epic->id)->assertOk();
    }

    public function test_an_epic_from_another_project_cannot_be_assigned(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->enabled($owner, $ws, 'MINE');
        $other = $this->enabled($owner, $ws, 'THEIRS', 'Other');

        $foreign = $this->epic($ws, $other, 'Theirs');
        $item = $this->workItem($owner, $project, 'Some work');

        // §21: the API confirms the epic belongs to the SAME project as the work item.
        $this->assign($owner, $project, $item, $foreign->id)
            ->assertStatus(422)->assertJsonValidationErrors('epic_id');
    }

    public function test_an_epic_assignment_freezes_while_the_feature_is_off(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->enabled($owner, $ws);
        $epic = $this->epic($ws, $project, 'Customer Onboarding Improvement');
        $second = $this->epic($ws, $project, 'Another');
        $item = $this->workItem($owner, $project, 'Some work');
        $this->assign($owner, $project, $item, $epic->id)->assertOk();

        $this->disableEpics($owner, $project);

        // No new assignment…
        $other = $this->workItem($owner, $project, 'Something else');
        $this->assign($owner, $project, $other, $epic->id)->assertStatus(422);

        // …no change of epic…
        $this->assign($owner, $project, $item, $second->id)->assertStatus(422);

        // …and no clearing either. Clearing while disabled would destroy exactly the
        // historical association the disable rules require to survive, so the value is frozen
        // rather than merely uneditable-in-the-UI.
        $this->assign($owner, $project, $item, null)->assertStatus(422);
        $this->assertSame($epic->id, $ws->run(fn () => WorkItem::find($item->id)->epic_id));

        // Editing anything ELSE on that work item still works — the form echoes every field
        // back on save, so re-sending the same epic must not be read as a change.
        $this->patchItem($owner, $project, $item, ['title' => 'Renamed', 'epic_id' => $epic->id])
            ->assertOk()->assertJsonPath('item.title', 'Renamed');

        // The picker offers nothing, so a new work item cannot be given one (§ New Work Items).
        $bootstrap = $this->actingAs($owner)->get(route('projects.work-items', $project))
            ->assertOk()->viewData('bootstrap');
        $this->assertFalse($bootstrap['epicsEnabled']);
        $this->assertSame([], $bootstrap['epics']);

        // But the assignment is still ON the row, so the drawer can show it read-only.
        $row = collect($bootstrap['items'])->firstWhere('id', $item->id);
        $this->assertSame('Customer Onboarding Improvement', $row['epic']['title']);
    }

    public function test_work_item_history_keeps_its_epic_entries_while_disabled(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->enabled($owner, $ws);
        $epic = $this->epic($ws, $project, 'Customer Onboarding Improvement');
        $item = $this->workItem($owner, $project, 'Redesign onboarding');
        $this->assign($owner, $project, $item, $epic->id)->assertOk();

        $this->disableEpics($owner, $project);

        // Historical epic information stays in the work item's audit record — disabling the
        // feature is a configuration change, and history is not configuration.
        $entry = $ws->run(fn () => WorkItemActivity::where('work_item_id', $item->id)
            ->where('field', 'epic')->latest('id')->first());

        $this->assertNotNull($entry);
        $this->assertSame('Customer Onboarding Improvement', $entry->meta['new_label']);
    }

    // ================= §24.17: activity =================

    public function test_epic_changes_are_recorded_in_activity(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->enabled($owner, $ws);
        $epic = $this->epic($ws, $project, 'Improve Student Recruitment');
        $item = $this->workItem($owner, $project, 'Redesign admissions page');

        $this->actingAs($owner)->patchJson(route('projects.epics.update', [
            'project' => $project->id, 'epic' => $epic->id,
        ]), ['status' => 'in_progress'])->assertOk();

        $this->actingAs($owner)->postJson(route('projects.epics.items.store', [
            'project' => $project->id, 'epic' => $epic->id,
        ]), ['work_item_ids' => [$item->id]])->assertOk();

        $feed = $this->actingAs($owner)->get(route('projects.epics.show', [
            'project' => $project->id, 'epic' => $epic->id,
        ]))->assertOk()->viewData('bootstrap')['activity'];

        $events = array_column($feed, 'event');
        $this->assertContains('updated', $events);
        $this->assertContains('work_item_added', $events);

        // §22: the label is frozen at write time, so renaming the status later cannot rewrite
        // what the feed says happened.
        $entry = $ws->run(fn () => EpicActivity::where('epic_id', $epic->id)
            ->where('field', 'status')->first());
        $this->assertSame('In Progress', $entry->meta['new_label']);
    }

    // ================= §24.18: permissions =================

    public function test_a_commenter_may_read_epics_but_not_create_or_change_them(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->enabled($owner, $ws);
        $epic = $this->epic($ws, $project, 'Improve Student Recruitment');

        $sam = $this->member($ws, 'member', 'sam@example.com');
        $ws->run(fn () => ProjectMember::create([
            'tenant_id' => $ws->id, 'project_id' => $project->id,
            'user_id' => $sam->id, 'role' => 'commenter',
        ]));

        // §15: View yes, Create/Edit no.
        $this->actingAs($sam)->get(route('projects.epics', $project))->assertOk();
        $this->actingAs($sam)->postJson(route('projects.epics.store', $project), ['title' => 'Nope'])
            ->assertStatus(403);
        $this->actingAs($sam)->patchJson(route('projects.epics.update', [
            'project' => $project->id, 'epic' => $epic->id,
        ]), ['title' => 'Renamed'])->assertStatus(403);
    }

    // ------------------------------------------------------------------ helpers

    private function enabled(User $owner, Workspace $ws, string $identifier = 'TESTI', string $name = 'Website Redesign'): Project
    {
        $project = $this->makeProject($owner, $ws, ['identifier' => $identifier, 'name' => $name]);
        $this->enableEpics($owner, $project);
        // Seeds the project's work item states, which progress counts by group.
        $this->actingAs($owner)->get(route('projects.work-items', $project));

        return $project;
    }

    private function enableEpics(User $owner, Project $project): void
    {
        $this->enableFeature($owner, $project, 'epics');
    }

    private function disableEpics(User $owner, Project $project): void
    {
        $this->actingAs($owner)->postJson(route('projects.settings.features.toggle', $project), [
            'feature' => 'epics', 'enabled' => false, 'confirm' => true,
        ])->assertOk();
    }

    private function enableFeature(User $owner, Project $project, string $feature): void
    {
        $this->actingAs($owner)->postJson(route('projects.settings.features.toggle', $project), [
            'feature' => $feature, 'enabled' => true,
        ])->assertOk();
    }

    private function epic(Workspace $ws, Project $project, string $title): Epic
    {
        return $ws->run(fn () => Epic::create([
            'project_id' => $project->id,
            'identifier' => Epic::nextIdentifier($project->id),
            'title' => $title,
            'status' => 'backlog',
        ]));
    }

    private function workItem(User $actor, Project $project, string $title): WorkItem
    {
        $id = $this->actingAs($actor)
            ->postJson(route('projects.work-items.store', $project), ['title' => $title])
            ->assertStatus(201)->json('item.id');

        return WorkItem::withoutTenancy()->find($id);
    }

    /** PATCH the Epic property, the way the drawer chip does. */
    private function assign(User $actor, Project $project, WorkItem $item, ?int $epicId)
    {
        return $this->patchItem($actor, $project, $item, ['epic_id' => $epicId]);
    }

    private function patchItem(User $actor, Project $project, WorkItem $item, array $body)
    {
        return $this->actingAs($actor)->patchJson(
            route('projects.work-items.update', ['project' => $project->id, 'workItem' => $item->id]),
            $body,
        );
    }

    /** @return array<string, mixed> */
    private function epicCard(User $actor, Project $project, Epic $epic): array
    {
        $epics = $this->actingAs($actor)->get(route('projects.epics', $project))
            ->assertOk()->viewData('bootstrap')['epics'];

        foreach ($epics as $card) {
            if ($card['id'] === $epic->id) {
                return $card;
            }
        }

        $this->fail("Epic {$epic->id} is not in the list.");
    }
}
