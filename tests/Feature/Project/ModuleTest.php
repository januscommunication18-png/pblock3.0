<?php

namespace Tests\Feature\Project;

use App\Models\Module;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkItemActivity;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Modules.
 * Source: ProjectBlock 3.0 — Module Management Requirements; §21's acceptance criteria.
 */
class ModuleTest extends ProjectTestCase
{
    use RefreshDatabase;

    public function test_the_modules_screen_loads_with_members_attached(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->enabled($owner, $ws);
        $sarah = $this->member($ws, 'member', 'sarah@example.com');

        $module = $this->module($ws, $project, 'Customer Portal');
        $ws->run(fn () => $module->members()->attach($sarah->id));

        // The screen EAGER-LOADS members, which builds the relation on a blank model — the
        // moment `$this->tenant_id` is null. withPivotValue() rejects null, so this whole
        // page 500'd as soon as any module had a member. Rendering it is the regression test.
        $bootstrap = $this->actingAs($owner)->get(route('projects.modules', $project))
            ->assertOk()->viewData('bootstrap');

        $this->assertSame('Customer Portal', $bootstrap['modules'][0]['title']);
        $this->assertSame([$sarah->id], array_column($bootstrap['modules'][0]['members'], 'id'));
    }

    public function test_every_tenant_stamped_pivot_survives_a_blank_instance(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->enabled($owner, $ws);

        // The same flaw sat in four relations. Asserting the shape rather than one symptom,
        // because the next one added would otherwise reintroduce it silently.
        $ws->run(function () {
            foreach ([
                [Module::class, 'members'],
                [Module::class, 'workItems'],
                [WorkItem::class, 'modules'],
                [Project::class, 'subscribers'],
            ] as [$class, $relation]) {
                (new $class)->{$relation}();
            }
        });

        $this->addToAssertionCount(1);
    }

    // ================= §4: enablement =================

    public function test_the_tab_appears_only_when_modules_is_on_or_has_history(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);

        // Feature Disable §5/§10 — never enabled, no records: the tab is not there at all.
        $tabs = $this->actingAs($owner)->get(route('projects.modules', $project))
            ->assertOk()->viewData('tabs');
        $this->assertNotContains('modules', array_column($tabs, 'key'));

        $this->enable($owner, $project);

        $tabs = $this->actingAs($owner)->get(route('projects.modules', $project))
            ->assertOk()->viewData('tabs');
        $this->assertContains('modules', array_column($tabs, 'key'));
        $this->assertSame('enabled', collect($tabs)->firstWhere('key', 'modules')['state']);
    }

    public function test_disabling_modules_keeps_every_module_and_link(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->enabled($owner, $ws);
        $module = $this->module($ws, $project, 'Billing Integration');
        $item = $this->workItem($owner, $project, 'Wire the gateway');

        $this->actingAs($owner)->postJson(route('projects.modules.items.store', [
            'project' => $project->id, 'module' => $module->id,
        ]), ['work_item_ids' => [$item->id]])->assertOk();

        $this->actingAs($owner)->postJson(route('projects.settings.features.toggle', $project), [
            'feature' => 'modules', 'enabled' => false, 'confirm' => true,
        ])->assertOk();

        // Feature Disable §3: nothing is deleted, and the page stays readable — a module with
        // history has to remain reachable, so the tab stays too, marked Disabled.
        $bootstrap = $this->actingAs($owner)->get(route('projects.modules', $project))
            ->assertOk()->viewData('bootstrap');
        $this->assertFalse($bootstrap['featureEnabled']);
        $this->assertFalse($bootstrap['canCreate']);
        $this->assertSame(['Billing Integration'], array_column($bootstrap['modules'], 'title'));

        $tabs = $this->actingAs($owner)->get(route('projects.modules', $project))->viewData('tabs');
        $this->assertSame('disabled', collect($tabs)->firstWhere('key', 'modules')['state']);

        $this->assertTrue($ws->run(fn () => Module::whereKey($module->id)->exists()));
        $this->assertSame(1, $ws->run(fn () => Module::find($module->id)->workItems()->count()));

        // …and every write is refused, not merely hidden (§3).
        $this->actingAs($owner)->postJson(route('projects.modules.store', $project), ['title' => 'New'])
            ->assertStatus(403);
        $this->actingAs($owner)->patchJson(route('projects.modules.update', [
            'project' => $project->id, 'module' => $module->id,
        ]), ['title' => 'Renamed'])->assertStatus(403);
        $this->actingAs($owner)->deleteJson(route('projects.modules.destroy', [
            'project' => $project->id, 'module' => $module->id,
        ]))->assertStatus(403);

        // §8: re-enabling restores everything with no migration and nothing to recreate.
        $this->enable($owner, $project);
        $this->actingAs($owner)->get(route('projects.modules', $project))->assertOk();
        $this->actingAs($owner)->patchJson(route('projects.modules.update', [
            'project' => $project->id, 'module' => $module->id,
        ]), ['title' => 'Billing Integration'])->assertOk();
    }

    public function test_the_module_detail_mounts_the_work_items_screen(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->enabled($owner, $ws);
        $module = $this->module($ws, $project, 'Billing Integration');
        $item = $this->workItem($owner, $project, 'Wire the gateway');

        $this->actingAs($owner)->postJson(route('projects.modules.items.store', [
            'project' => $project->id, 'module' => $module->id,
        ]), ['work_item_ids' => [$item->id]])->assertOk();

        $screen = $this->actingAs($owner)->get(route('projects.modules.show', [
            'project' => $project->id, 'module' => $module->id,
        ]))->assertOk()->viewData('bootstrap')['workItems'];

        // The same screen the project's list uses, so a row's chips are editable and clicking
        // one opens the same drawer.
        $this->assertTrue($screen['canEdit']);
        $this->assertArrayHasKey('update', $screen['endpoints']);
        $this->assertSame(['Wire the gateway'], array_column($screen['items'], 'title'));

        // §9.4: "Add work item" here adds work to THIS module.
        $this->assertSame(['module_ids' => [$module->id]], $screen['seed']);
        $this->assertTrue($screen['embedded']);
    }

    // ================= §5: creation =================

    public function test_a_module_needs_only_a_title_and_starts_in_backlog(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->enabled($owner, $ws);

        $this->actingAs($owner)->postJson(route('projects.modules.store', $project), [
            'title' => '  Mobile Application  ',
        ])->assertStatus(201)
            // §15: the title is trimmed; §5.2: Backlog is the default.
            ->assertJsonPath('module.title', 'Mobile Application')
            ->assertJsonPath('module.status', 'backlog');
    }

    public function test_a_module_takes_dates_a_lead_and_members(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->enabled($owner, $ws);
        $lead = $this->member($ws, 'member', 'lead@example.com');
        $sarah = $this->member($ws, 'member', 'sarah@example.com');

        $module = $this->actingAs($owner)->postJson(route('projects.modules.store', $project), [
            'title' => 'Authentication Upgrade',
            'description' => 'SSO and MFA.',
            'status' => 'in_progress',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(30)->toDateString(),
            'lead_user_id' => $lead->id,
            'member_ids' => [$sarah->id, $lead->id],
        ])->assertStatus(201)->json('module');

        $this->assertSame('in_progress', $module['status']);
        $this->assertSame($lead->id, $module['lead']['id']);
        $this->assertEqualsCanonicalizing([$sarah->id, $lead->id], array_column($module['members'], 'id'));
    }

    public function test_an_end_date_before_the_start_is_rejected(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->enabled($owner, $ws);

        $this->actingAs($owner)->postJson(route('projects.modules.store', $project), [
            'title' => 'Backwards',
            'start_date' => now()->addDays(5)->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors('end_date');
    }

    public function test_a_blank_title_is_rejected(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->enabled($owner, $ws);

        // §15: whitespace is not a title.
        $this->actingAs($owner)->postJson(route('projects.modules.store', $project), ['title' => '   '])
            ->assertStatus(422)->assertJsonValidationErrors('title');
    }

    // ================= §9/§10: work items and progress =================

    public function test_work_items_can_be_added_and_removed_without_being_deleted(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->enabled($owner, $ws);
        $module = $this->module($ws, $project, 'Q4 Release');
        $item = $this->workItem($owner, $project, 'Ship the thing');

        $url = ['project' => $project->id, 'module' => $module->id];

        // `gridItems` is what the grid on the detail page renders; `items` is what the counter
        // above it reads. They come back together so the two cannot disagree until a reload
        // (embedded-work-item-grid.md).
        $this->actingAs($owner)->postJson(route('projects.modules.items.store', $url), [
            'work_item_ids' => [$item->id],
        ])->assertOk()->assertJsonCount(1, 'items')->assertJsonCount(1, 'gridItems');

        // §15: adding the same item twice must not create a second relationship.
        $this->actingAs($owner)->postJson(route('projects.modules.items.store', $url), [
            'work_item_ids' => [$item->id],
        ])->assertOk()->assertJsonCount(1, 'items');

        $this->actingAs($owner)->deleteJson(route('projects.modules.items.destroy',
            $url + ['workItem' => $item->id]))->assertOk()
            ->assertJsonCount(0, 'items')->assertJsonCount(0, 'gridItems');

        // §8.3: only the association goes.
        $this->assertNotNull($ws->run(fn () => WorkItem::find($item->id)));
    }

    public function test_progress_counts_completed_work_items(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->enabled($owner, $ws);
        $module = $this->module($ws, $project, 'Phase 1');

        $done = $this->workItem($owner, $project, 'Finished');
        $open = $this->workItem($owner, $project, 'Outstanding');

        $this->actingAs($owner)->postJson(route('projects.modules.items.store', [
            'project' => $project->id, 'module' => $module->id,
        ]), ['work_item_ids' => [$done->id, $open->id]])->assertOk();

        // §10.2: "completed" is the state's GROUP, never a state named "Done" — states are
        // user-renameable, so matching on the name would break the moment someone edited one.
        $state = $ws->run(fn () => $project->states()->where('group', 'completed')->first());
        $this->actingAs($owner)->patchJson(route('projects.work-items.update', [
            'project' => $project->id, 'workItem' => $done->id,
        ]), ['state_id' => $state->id])->assertOk();

        $module = collect($this->actingAs($owner)->get(route('projects.modules', $project))
            ->assertOk()->viewData('bootstrap')['modules'])->firstWhere('id', $module->id);

        $this->assertSame(2, $module['progress']['total']);
        $this->assertSame(1, $module['progress']['completed']);
        $this->assertSame(50, $module['progress']['percent']);
    }

    public function test_a_work_item_from_another_project_cannot_be_added(): void
    {
        [$owner, $ws] = $this->owner();
        $mine = $this->enabled($owner, $ws, 'mine');
        $theirs = $this->makeProject($owner, $ws, ['name' => 'Other', 'identifier' => 'other']);

        $module = $this->module($ws, $mine, 'Mine');
        $foreign = $this->workItem($owner, $theirs, 'Their work');

        // §15/§20: project scoping is enforced server-side, not left to the picker.
        $this->actingAs($owner)->postJson(route('projects.modules.items.store', [
            'project' => $mine->id, 'module' => $module->id,
        ]), ['work_item_ids' => [$foreign->id]])->assertOk()->assertJsonCount(0, 'items');
    }

    // ================= §9: the work item Module property =================

    public function test_the_module_property_appears_on_work_items_only_when_enabled(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $this->workItem($owner, $project, 'Anything');

        $b = $this->actingAs($owner)->get(route('projects.work-items', $project))
            ->assertOk()->viewData('bootstrap');
        $this->assertFalse($b['modulesEnabled']);
        $this->assertSame([], $b['modules']);

        $this->enable($owner, $project);
        $this->module($ws, $project, 'Customer Portal');

        $b = $this->actingAs($owner)->get(route('projects.work-items', $project))
            ->assertOk()->viewData('bootstrap');
        $this->assertTrue($b['modulesEnabled']);
        $this->assertSame(['Customer Portal'], array_column($b['modules'], 'title'));
    }

    public function test_a_work_item_can_belong_to_several_modules_at_once(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->enabled($owner, $ws);
        $portal = $this->module($ws, $project, 'Customer Portal');
        $release = $this->module($ws, $project, 'Q4 Release');
        $item = $this->workItem($owner, $project, 'Redesign the header');

        // §9.3: the whole reason this is many-to-many — a functional module AND a release
        // module, rather than one replacing the other the way a cycle does.
        $modules = $this->assign($owner, $project, $item, [$portal->id, $release->id])
            ->assertOk()->json('item.modules');

        $this->assertEqualsCanonicalizing(['Customer Portal', 'Q4 Release'], array_column($modules, 'title'));

        // Removing one leaves the other.
        $modules = $this->assign($owner, $project, $item, [$release->id])->assertOk()->json('item.modules');
        $this->assertSame(['Q4 Release'], array_column($modules, 'title'));
    }

    public function test_module_changes_are_recorded_in_history(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->enabled($owner, $ws);
        $portal = $this->module($ws, $project, 'Customer Portal');
        $item = $this->workItem($owner, $project, 'Tracked');

        $this->assign($owner, $project, $item, [$portal->id])->assertOk();

        // §17: the feed names the module, resolved at write time so renaming it later cannot
        // rewrite what the history says happened.
        $entry = $ws->run(fn () => WorkItemActivity::where('work_item_id', $item->id)
            ->where('field', 'modules')->latest('id')->first());

        $this->assertNotNull($entry);
        $this->assertSame(['Customer Portal'], $entry->meta['new_labels']);
    }

    public function test_an_archived_or_foreign_module_cannot_be_assigned(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->enabled($owner, $ws, 'mine');
        $other = $this->makeProject($owner, $ws, ['name' => 'Other', 'identifier' => 'other']);
        $item = $this->workItem($owner, $project, 'Some work');

        // §12.2/§15: an archived module is out of the picker and refused by the API.
        $archived = $this->module($ws, $project, 'Retired');
        $this->actingAs($owner)->postJson(route('projects.modules.archive', [
            'project' => $project->id, 'module' => $archived->id,
        ]))->assertOk();

        $this->assign($owner, $project, $item, [$archived->id])
            ->assertStatus(422)->assertJsonValidationErrors('module_ids.0');

        // §15: and one from another project is not selectable at all.
        $foreign = $this->module($ws, $other, 'Theirs');
        $this->assign($owner, $project, $item, [$foreign->id])
            ->assertStatus(422)->assertJsonValidationErrors('module_ids.0');
    }

    public function test_no_new_module_assignment_while_the_feature_is_off(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->enabled($owner, $ws);
        $module = $this->module($ws, $project, 'Customer Portal');
        $item = $this->workItem($owner, $project, 'Some work');
        $this->assign($owner, $project, $item, [$module->id])->assertOk();

        $this->actingAs($owner)->postJson(route('projects.settings.features.toggle', $project), [
            'feature' => 'modules', 'enabled' => false, 'confirm' => true,
        ])->assertOk();

        // Feature Disable §3: the assignment FREEZES. The existing link survives…
        $this->assertSame(1, $ws->run(fn () => WorkItem::find($item->id)->modules()->count()));

        // …nothing new is accepted…
        $other = $this->module($ws, $project, 'Another');
        $this->assign($owner, $project, $item, [$module->id, $other->id])->assertStatus(422);

        // …and removal is refused too. Clearing while disabled would destroy exactly the
        // historical relationship disable exists to protect, so the set is held as it is.
        $this->assign($owner, $project, $item, [])
            ->assertStatus(422)->assertJsonValidationErrors('module_ids');
        $this->assertSame(1, $ws->run(fn () => WorkItem::find($item->id)->modules()->count()));

        // Editing anything ELSE on that work item still works — the form echoes the whole set
        // back on save, so re-sending it unchanged must not read as a removal.
        $this->patchItem($owner, $project, $item, ['title' => 'Renamed', 'module_ids' => [$module->id]])
            ->assertOk()->assertJsonPath('item.title', 'Renamed');
    }

    // ================= §12/§13: archive and delete =================

    public function test_archiving_hides_a_module_without_losing_it(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->enabled($owner, $ws);
        $module = $this->module($ws, $project, 'Old Initiative');

        $this->actingAs($owner)->postJson(route('projects.modules.archive', [
            'project' => $project->id, 'module' => $module->id,
        ]))->assertOk()->assertJsonPath('module.archived', true);

        $this->assertTrue($ws->run(fn () => Module::find($module->id)->isArchived()));

        $this->actingAs($owner)->postJson(route('projects.modules.restore', [
            'project' => $project->id, 'module' => $module->id,
        ]))->assertOk()->assertJsonPath('module.archived', false);
    }

    public function test_deleting_a_module_keeps_its_work_items(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->enabled($owner, $ws);
        $module = $this->module($ws, $project, 'Doomed');
        $item = $this->workItem($owner, $project, 'Survivor');

        $this->actingAs($owner)->postJson(route('projects.modules.items.store', [
            'project' => $project->id, 'module' => $module->id,
        ]), ['work_item_ids' => [$item->id]])->assertOk();

        $this->actingAs($owner)->deleteJson(route('projects.modules.destroy', [
            'project' => $project->id, 'module' => $module->id,
        ]))->assertOk();

        // §13.1: the module goes, the work items do not.
        $this->assertNotNull($ws->run(fn () => WorkItem::find($item->id)));
    }

    // ================= §14: permissions =================

    public function test_a_module_from_another_workspace_is_not_found(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->enabled($owner, $ws);
        [$outsider] = $this->owner('other-co');

        // 404 rather than 403: the response must not confirm the project exists.
        $this->actingAs($outsider)->get(route('projects.modules', $project))->assertNotFound();
    }

    // ================= helpers =================

    private function enable(User $actor, Project $project): void
    {
        $this->actingAs($actor)->postJson(route('projects.settings.features.toggle', $project), [
            'feature' => 'modules', 'enabled' => true,
        ])->assertOk();

        $project->refresh();
    }

    /** A project with Modules switched on. */
    private function enabled(User $owner, Workspace $ws, string $identifier = 'web'): Project
    {
        $project = $this->makeProject($owner, $ws, ['identifier' => $identifier]);
        $this->enable($owner, $project);

        return $project;
    }

    private function module(Workspace $ws, Project $project, string $title): Module
    {
        return $ws->run(fn () => Module::create([
            'project_id' => $project->id,
            'title' => $title,
            'status' => 'backlog',
        ]));
    }

    /** PATCH the item's Module property, the way the chip picker does. */
    private function assign(User $actor, Project $project, WorkItem $item, array $moduleIds)
    {
        return $this->patchItem($actor, $project, $item, ['module_ids' => $moduleIds]);
    }

    private function patchItem(User $actor, Project $project, WorkItem $item, array $body)
    {
        return $this->actingAs($actor)->patchJson(
            route('projects.work-items.update', ['project' => $project->id, 'workItem' => $item->id]),
            $body,
        );
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
}
