<?php

namespace Tests\Feature\Project;

use App\Models\Project;
use App\Models\ProjectItemLabel;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Project-level labels.
 * Source: ProjectBlock 3.0 — Project-Level Labels Requirements; §30's acceptance criteria.
 */
class ProjectLabelTest extends ProjectTestCase
{
    use RefreshDatabase;

    // ================= §2: enablement =================

    public function test_labels_are_on_by_default_and_can_be_switched_off(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'TESTI']);
        $this->actingAs($owner)->get(route('projects.work-items', $project));

        // §2: ON by default — unlike every other optional feature.
        $this->assertTrue($this->workItemBootstrap($owner, $project)['labelsEnabled']);

        $this->toggle($owner, $project, false);
        $this->assertFalse($this->workItemBootstrap($owner, $project)['labelsEnabled']);
    }

    public function test_switching_labels_off_asks_first_when_labels_exist(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'TESTI']);
        $this->label($owner, $project, 'Bug');

        // §4: the confirmation is a real gate, not a UI nicety.
        $this->actingAs($owner)->postJson(route('projects.settings.features.toggle', $project), [
            'feature' => 'labels', 'enabled' => false,
        ])->assertStatus(409)->assertJsonPath('confirm', true)->assertJsonPath('count', 1);

        $this->assertTrue($project->fresh()->featureEnabled('labels'));

        $this->toggle($owner, $project, false);
        $this->assertFalse($project->fresh()->featureEnabled('labels'));
    }

    // ================= §7/§8/§9: create, validate, edit =================

    public function test_a_label_takes_a_name_colour_and_description(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'TESTI']);

        $labels = $this->actingAs($owner)->postJson(route('projects.settings.labels.store', $project), [
            'name' => '  Bug  ', 'color' => '#EF4444', 'description' => 'Product defect',
        ])->assertOk()->json('labels');

        // §8: leading and trailing spaces are removed.
        $this->assertSame('Bug', $labels[0]['name']);
        $this->assertSame('Product defect', $labels[0]['description']);
        $this->assertSame(0, $labels[0]['usage']);
        $this->assertFalse($labels[0]['archived']);
    }

    public function test_a_duplicate_name_is_refused_within_a_project_but_not_across_projects(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'MINE']);
        $other = $this->makeProject($owner, $ws, ['identifier' => 'OTHER', 'name' => 'Other']);

        $this->label($owner, $project, 'Bug');

        // §8: "A label named Bug already exists in this project."
        $this->actingAs($owner)->postJson(route('projects.settings.labels.store', $project), [
            'name' => 'Bug', 'color' => '#EF4444',
        ])->assertStatus(422)->assertJsonValidationErrors('name');

        // §20: the same name in another project is fine — labels are project-scoped.
        $this->actingAs($owner)->postJson(route('projects.settings.labels.store', $other), [
            'name' => 'Bug', 'color' => '#EF4444',
        ])->assertOk();
    }

    public function test_renaming_a_label_updates_it_on_every_work_item(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'TESTI']);
        $this->actingAs($owner)->get(route('projects.work-items', $project));

        $label = $this->label($owner, $project, 'Customer Issue');
        $item = $this->workItem($owner, $project, 'Something broke');
        $this->assign($owner, $project, $item, [$label->id])->assertOk();

        $this->actingAs($owner)->patchJson(route('projects.settings.labels.update', [
            'project' => $project->id, 'label' => $label->id,
        ]), ['name' => 'Customer Request', 'color' => '#EF4444'])->assertOk();

        // §9: one row changes and every display follows, because the chip reads the relation
        // rather than a copy of the name on the pivot.
        $this->assertSame(['Customer Request'], array_column(
            $this->workItemCard($owner, $project, $item)['labels'], 'name',
        ));
    }

    // ================= §10/§11: delete and archive =================

    public function test_deleting_a_label_removes_it_from_work_items_without_deleting_them(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'TESTI']);
        $this->actingAs($owner)->get(route('projects.work-items', $project));

        $label = $this->label($owner, $project, 'Bug');
        $item = $this->workItem($owner, $project, 'Something broke');
        $this->assign($owner, $project, $item, [$label->id])->assertOk();

        // §24: the list reports usage, which is what §10's warning names.
        $this->assertSame(1, $this->labelList($owner, $project)[0]['usage']);

        $this->actingAs($owner)->deleteJson(route('projects.settings.labels.destroy', [
            'project' => $project->id, 'label' => $label->id,
        ]))->assertOk();

        // §10: the work item survives and simply loses the label.
        $this->assertTrue($ws->run(fn () => WorkItem::whereKey($item->id)->exists()));
        $this->assertSame([], $this->workItemCard($owner, $project, $item)['labels']);
    }

    public function test_an_archived_label_leaves_the_picker_but_stays_on_its_work_items(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'TESTI']);
        $this->actingAs($owner)->get(route('projects.work-items', $project));

        $label = $this->label($owner, $project, 'Legacy');
        $item = $this->workItem($owner, $project, 'Old work');
        $this->assign($owner, $project, $item, [$label->id])->assertOk();

        $this->actingAs($owner)->postJson(route('projects.settings.labels.archive', [
            'project' => $project->id, 'label' => $label->id,
        ]))->assertOk();

        // §11: out of the picker…
        $this->assertSame([], $this->workItemBootstrap($owner, $project)['labels']);

        // …still on the work item that has it…
        $this->assertSame(['Legacy'], array_column($this->workItemCard($owner, $project, $item)['labels'], 'name'));

        // …and refused for anything new (§28.9).
        $other = $this->workItem($owner, $project, 'New work');
        $this->assign($owner, $project, $other, [$label->id])
            ->assertStatus(422)->assertJsonValidationErrors('label_ids.0');

        // Restorable.
        $this->actingAs($owner)->postJson(route('projects.settings.labels.archive', [
            'project' => $project->id, 'label' => $label->id,
        ]))->assertOk();
        $this->assign($owner, $project, $other, [$label->id])->assertOk();
    }

    // ================= §3/§5: disable and re-enable =================

    public function test_disabling_labels_freezes_assignment_and_keeps_everything(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'TESTI']);
        $this->actingAs($owner)->get(route('projects.work-items', $project));

        $bug = $this->label($owner, $project, 'Bug');
        $backend = $this->label($owner, $project, 'Backend');
        $item = $this->workItem($owner, $project, 'Something broke');
        $this->assign($owner, $project, $item, [$bug->id])->assertOk();

        $this->toggle($owner, $project, false);

        // §3/§28.5: the label and the assignment are kept…
        $this->assertSame(2, $ws->run(fn () => ProjectItemLabel::where('project_id', $project->id)->count()));
        $this->assertSame(['Bug'], array_column($this->workItemCard($owner, $project, $item)['labels'], 'name'));

        // …the picker is empty, so nothing can be chosen for a new work item…
        $b = $this->workItemBootstrap($owner, $project);
        $this->assertFalse($b['labelsEnabled']);
        $this->assertSame([], $b['labels']);

        // …nothing new is accepted…
        $this->assign($owner, $project, $item, [$bug->id, $backend->id])->assertStatus(422);

        // …removal is frozen too, for the same reason the other features freeze theirs:
        // clearing while disabled would destroy the assignment §28.5 requires to survive…
        $this->assign($owner, $project, $item, [])
            ->assertStatus(422)->assertJsonValidationErrors('label_ids');

        // …and no new labels can be created (§3).
        $this->actingAs($owner)->postJson(route('projects.settings.labels.store', $project), [
            'name' => 'Nope', 'color' => '#EF4444',
        ])->assertStatus(403);

        // §5: re-enabling restores the list and the assignments, with nothing to recreate.
        $this->toggle($owner, $project, true);
        $b = $this->workItemBootstrap($owner, $project);
        $this->assertTrue($b['labelsEnabled']);
        $this->assertEqualsCanonicalizing(['Bug', 'Backend'], array_column($b['labels'], 'name'));
        $this->assign($owner, $project, $item, [$bug->id, $backend->id])->assertOk();
    }

    public function test_the_row_label_chip_follows_the_project_setting(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'TESTI']);
        $this->actingAs($owner)->get(route('projects.work-items', $project));
        $this->label($owner, $project, 'Bug');

        // The list grid draws its own chips, so it needs the setting — the payload's `labels`
        // list being empty is not the same signal: a row keeps its assigned labels either way.
        $this->assertTrue($this->workItemBootstrap($owner, $project)['labelsEnabled']);

        $this->toggle($owner, $project, false);
        $this->assertFalse($this->workItemBootstrap($owner, $project)['labelsEnabled']);

        // The cycle and module detail grids draw the SAME row, so they are told the same
        // thing — otherwise one screen would offer a label field the project has turned off.
        foreach (['cycles', 'modules'] as $feature) {
            $this->actingAs($owner)->postJson(route('projects.settings.features.toggle', $project), [
                'feature' => $feature, 'enabled' => true,
            ])->assertOk();

            $bootstrap = $this->actingAs($owner)->get(route("projects.{$feature}", $project))
                ->assertOk()->viewData('bootstrap');
            $this->assertFalse($bootstrap['labelsEnabled'], $feature);
        }
    }

    // ================= §19: many labels per work item =================

    public function test_a_work_item_carries_several_labels(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'TESTI']);
        $this->actingAs($owner)->get(route('projects.work-items', $project));

        $ids = collect(['Bug', 'Payment', 'Backend'])
            ->map(fn ($n) => $this->label($owner, $project, $n)->id)->all();
        $item = $this->workItem($owner, $project, 'Payment fails after Stripe timeout');

        $card = $this->assign($owner, $project, $item, $ids)->assertOk()->json('item');
        $this->assertEqualsCanonicalizing(['Bug', 'Payment', 'Backend'], array_column($card['labels'], 'name'));

        // §18: and they can be removed again while the feature is on.
        $card = $this->assign($owner, $project, $item, [$ids[0]])->assertOk()->json('item');
        $this->assertSame(['Bug'], array_column($card['labels'], 'name'));
    }

    // ================= §25: permissions =================

    public function test_only_a_project_admin_manages_labels(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'TESTI']);
        $this->actingAs($owner)->get(route('projects.work-items', $project));
        $label = $this->label($owner, $project, 'Bug');

        $sam = $this->member($ws, 'member', 'sam@example.com');
        $ws->run(fn () => ProjectMember::create([
            'tenant_id' => $ws->id, 'project_id' => $project->id,
            'user_id' => $sam->id, 'role' => ProjectMember::ROLE_CONTRIBUTOR,
        ]));

        // §25: a Contributor may assign and remove labels on work items…
        $item = $this->workItem($owner, $project, 'Something broke');
        $this->assign($sam, $project, $item, [$label->id])->assertOk();
        $this->assign($sam, $project, $item, [])->assertOk();

        // …but may not manage the project's labels.
        $this->actingAs($sam)->postJson(route('projects.settings.labels.store', $project), [
            'name' => 'Nope', 'color' => '#EF4444',
        ])->assertStatus(403);
        $this->actingAs($sam)->deleteJson(route('projects.settings.labels.destroy', [
            'project' => $project->id, 'label' => $label->id,
        ]))->assertStatus(403);
    }

    // ------------------------------------------------------------------ helpers

    private function toggle(User $owner, Project $project, bool $enabled): void
    {
        $this->actingAs($owner)->postJson(route('projects.settings.features.toggle', $project), [
            'feature' => 'labels', 'enabled' => $enabled, 'confirm' => true,
        ])->assertOk();
    }

    private function label(User $owner, Project $project, string $name): ProjectItemLabel
    {
        $labels = $this->actingAs($owner)->postJson(route('projects.settings.labels.store', $project), [
            'name' => $name, 'color' => '#EF4444',
        ])->assertOk()->json('labels');

        $id = collect($labels)->firstWhere('name', $name)['id'];

        return ProjectItemLabel::withoutTenancy()->find($id);
    }

    /** @return array<int, array<string, mixed>> */
    private function labelList(User $owner, Project $project): array
    {
        return $this->actingAs($owner)
            ->get(route('projects.settings', ['project' => $project->id, 'section' => 'labels']))
            ->assertOk()->viewData('bootstrap')['labels'];
    }

    private function workItem(User $actor, Project $project, string $title): WorkItem
    {
        $id = $this->actingAs($actor)
            ->postJson(route('projects.work-items.store', $project), ['title' => $title])
            ->assertStatus(201)->json('item.id');

        return WorkItem::withoutTenancy()->find($id);
    }

    /** @param  array<int, int>  $labelIds */
    private function assign(User $actor, Project $project, WorkItem $item, array $labelIds)
    {
        return $this->actingAs($actor)->patchJson(
            route('projects.work-items.update', ['project' => $project->id, 'workItem' => $item->id]),
            ['label_ids' => $labelIds],
        );
    }

    /** @return array<string, mixed> */
    private function workItemBootstrap(User $actor, Project $project): array
    {
        return $this->actingAs($actor)->get(route('projects.work-items', $project))
            ->assertOk()->viewData('bootstrap');
    }

    /** @return array<string, mixed> */
    private function workItemCard(User $actor, Project $project, WorkItem $item): array
    {
        foreach ($this->workItemBootstrap($actor, $project)['items'] as $card) {
            if ($card['id'] === $item->id) {
                return $card;
            }
        }

        $this->fail("Work item {$item->id} is not in the list.");
    }
}
