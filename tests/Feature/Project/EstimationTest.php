<?php

namespace Tests\Feature\Project;

use App\Models\EstimateValue;
use App\Models\Project;
use App\Models\ProjectEstimation;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkItemActivity;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Estimation.
 * Source: ProjectBlock 3.0 — Project Estimation Requirements; §34's acceptance criteria.
 */
class EstimationTest extends ProjectTestCase
{
    use RefreshDatabase;

    // ================= AC-01 / AC-02: the property follows the feature =================

    public function test_the_estimate_property_appears_only_once_a_system_is_configured(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'TESTI']);
        $this->actingAs($owner)->get(route('projects.work-items', $project));

        // AC-01: off by default.
        $b = $this->workItemBootstrap($owner, $project);
        $this->assertFalse($b['estimatesEnabled']);
        $this->assertSame([], $b['estimates']);

        // §5: enabling alone is not enough — the property means nothing without values.
        $this->toggle($owner, $project, true);
        $this->assertFalse($this->workItemBootstrap($owner, $project)['estimatesEnabled']);

        $this->configure($owner, $project, 'points', 'fibonacci');

        // AC-02/AC-04: enabled and configured — only the configured values are offered.
        $b = $this->workItemBootstrap($owner, $project);
        $this->assertTrue($b['estimatesEnabled']);
        $this->assertSame(['1', '2', '3', '5', '8', '13'], array_column($b['estimates'], 'label'));
    }

    public function test_each_type_seeds_the_values_its_template_describes(): void
    {
        [$owner, $ws] = $this->owner();

        foreach ([
            ['points', 'linear', ['1', '2', '3', '4', '5', '6']],
            ['points', 'squares', ['1', '4', '9', '16', '25']],
            ['category', 'tshirt', ['XS', 'S', 'M', 'L', 'XL']],
            ['category', 'easy_to_hard', ['Easy', 'Medium', 'Hard']],
            ['time', 'standard', ['30m', '1h', '2h', '4h', '8h', '1d', '2d']],
        ] as $i => [$type, $template, $expected]) {
            $project = $this->makeProject($owner, $ws, ['identifier' => 'P'.$i, 'name' => 'Project '.$i]);
            $this->toggle($owner, $project, true);

            $estimation = $this->configure($owner, $project, $type, $template);
            $this->assertSame($expected, array_column($estimation['values'], 'label'), "{$type}/{$template}");
        }
    }

    public function test_points_carry_a_number_and_time_carries_minutes(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'PTS']);
        $this->toggle($owner, $project, true);

        // §32's rollups need something summable, and only one column means anything per type.
        $points = $this->configure($owner, $project, 'points', 'fibonacci')['values'];
        $this->assertEquals(13, $points[5]['numeric_value']);
        $this->assertNull($points[5]['duration_minutes']);

        $byLabel = collect($this->configure($owner, $project, 'time', 'standard')['values'])
            ->where('active', true)->keyBy('label');
        $this->assertSame(480, $byLabel['8h']['duration_minutes']);
        $this->assertNull($byLabel['8h']['numeric_value']);

        // A category has neither — XS cannot be added to S, so it can only be counted.
        // Filtered to ACTIVE: each reconfigure above archived the previous system rather than
        // deleting it (§23), so by now the list also holds the points and time values.
        $category = collect($this->configure($owner, $project, 'category', 'tshirt')['values'])
            ->where('active', true)->values();
        $this->assertSame('XS', $category[0]['label']);
        $this->assertNull($category[0]['numeric_value']);
        $this->assertNull($category[0]['duration_minutes']);
    }

    // ================= AC-03 / §14 / §15: assigning, changing, removing =================

    public function test_an_estimate_is_assigned_changed_and_removed(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->configured($owner, $ws);
        $values = $this->values($ws, $project);
        $item = $this->workItem($owner, $project, 'Build the login page');

        // AC-03: assigned, and the label travels with the row so the chip can read it.
        $card = $this->assign($owner, $project, $item, $values['3']->id)->assertOk()->json('item');
        $this->assertSame($values['3']->id, $card['estimate_value_id']);
        $this->assertSame('3', $card['estimate']['label']);

        // §14: changed.
        $card = $this->assign($owner, $project, $item, $values['5']->id)->assertOk()->json('item');
        $this->assertSame('5', $card['estimate']['label']);

        // §15: removed — "No Estimate" is a real choice, and it nulls the column.
        $card = $this->assign($owner, $project, $item, null)->assertOk()->json('item');
        $this->assertNull($card['estimate_value_id']);
        $this->assertNull($card['estimate']);
        $this->assertNull($ws->run(fn () => WorkItem::find($item->id)->estimate_value_id));
    }

    public function test_only_this_projects_active_values_can_be_assigned(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->configured($owner, $ws, 'MINE');
        $other = $this->configured($owner, $ws, 'THEIRS', 'Other');
        $item = $this->workItem($owner, $project, 'Some work');

        // AC-04 / §9: a value from another project's system is not a choice here.
        $foreign = $this->values($ws, $other)['5'];
        $this->assign($owner, $project, $item, $foreign->id)
            ->assertStatus(422)->assertJsonValidationErrors('estimate_value_id');

        // §21: nor is one that has been removed from this system.
        $archived = $this->values($ws, $project)['8'];
        $ws->run(fn () => EstimateValue::whereKey($archived->id)->update(['active' => false]));
        $this->assign($owner, $project, $item, $archived->id)
            ->assertStatus(422)->assertJsonValidationErrors('estimate_value_id');
    }

    // ================= AC-09: activity =================

    public function test_estimate_changes_appear_in_work_item_activity(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->configured($owner, $ws);
        $values = $this->values($ws, $project);
        $item = $this->workItem($owner, $project, 'Build the login page');

        $this->assign($owner, $project, $item, $values['3']->id)->assertOk();
        $this->assign($owner, $project, $item, $values['5']->id)->assertOk();

        $entries = $ws->run(fn () => WorkItemActivity::where('work_item_id', $item->id)
            ->where('field', 'estimate')->orderBy('id')->get());

        $this->assertCount(2, $entries);
        // §31: "changed Estimate from 3 to 5" — labels frozen at write time, so renaming or
        // archiving a value later cannot rewrite what the feed says happened.
        $this->assertNull($entries[0]->meta['old_label']);
        $this->assertSame('3', $entries[0]->meta['new_label']);
        $this->assertSame('3', $entries[1]->meta['old_label']);
        $this->assertSame('5', $entries[1]->meta['new_label']);
    }

    // ================= §10 / §20 / §21: editing the values =================

    public function test_values_are_added_renamed_reordered_and_removed(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->configured($owner, $ws);

        $estimation = $this->actingAs($owner)->postJson(
            route('projects.settings.estimation.values.store', $project), ['label' => '21'],
        )->assertOk()->json('estimation');
        $this->assertContains('21', array_column($estimation['values'], 'label'));

        $twentyOne = collect($estimation['values'])->firstWhere('label', '21');
        $this->actingAs($owner)->patchJson(route('projects.settings.estimation.values.update', [
            'project' => $project->id, 'value' => $twentyOne['id'],
        ]), ['label' => '20'])->assertOk();

        // §19: the order is stored, not derived — a category runs XS → S → M → L → XL.
        $ids = array_column($estimation['values'], 'id');
        $reversed = array_reverse($ids);
        $estimation = $this->actingAs($owner)->postJson(
            route('projects.settings.estimation.reorder', $project), ['ids' => $reversed],
        )->assertOk()->json('estimation');
        $this->assertSame($reversed, array_column($estimation['values'], 'id'));

        // A value nothing uses is genuinely deleted — no history to protect.
        $estimation = $this->actingAs($owner)->deleteJson(route('projects.settings.estimation.values.destroy', [
            'project' => $project->id, 'value' => $twentyOne['id'],
        ]))->assertOk()->assertJsonPath('archived', false)->json('estimation');
        $this->assertNotContains('20', array_column($estimation['values'], 'label'));
    }

    public function test_removing_a_value_in_use_archives_it_rather_than_deleting_it(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->configured($owner, $ws);
        $values = $this->values($ws, $project);
        $item = $this->workItem($owner, $project, 'Build the login page');
        $this->assign($owner, $project, $item, $values['13']->id)->assertOk();

        $resp = $this->actingAs($owner)->deleteJson(route('projects.settings.estimation.values.destroy', [
            'project' => $project->id, 'value' => $values['13']->id,
        ]))->assertOk()->assertJsonPath('archived', true);

        // §21: it stops being assignable, and the work item keeps reading correctly.
        $row = collect($resp->json('estimation.values'))->firstWhere('id', $values['13']->id);
        $this->assertFalse($row['active']);
        $this->assertSame(1, $row['in_use']);
        $this->assertSame($values['13']->id, $ws->run(fn () => WorkItem::find($item->id)->estimate_value_id));
        $this->assertSame('13', $this->workItemCard($owner, $project, $item)['estimate']['label']);

        // Out of the picker…
        $this->assertNotContains('13', array_column($this->workItemBootstrap($owner, $project)['estimates'], 'label'));

        // …until it is restored.
        $this->actingAs($owner)->postJson(route('projects.settings.estimation.values.restore', [
            'project' => $project->id, 'value' => $values['13']->id,
        ]))->assertOk();
        $this->assertContains('13', array_column($this->workItemBootstrap($owner, $project)['estimates'], 'label'));
    }

    // ================= AC-07: changing the type =================

    public function test_changing_the_type_keeps_every_existing_estimate(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->configured($owner, $ws);
        $values = $this->values($ws, $project);
        $item = $this->workItem($owner, $project, 'Build the login page');
        $this->assign($owner, $project, $item, $values['5']->id)->assertOk();

        $estimation = $this->configure($owner, $project, 'category', 'tshirt');

        // §9/§36: still one system, now the new one.
        $this->assertSame('category', $estimation['type']);
        $this->assertSame(['XS', 'S', 'M', 'L', 'XL'], array_column(
            array_values(array_filter($estimation['values'], fn ($v) => $v['active'])), 'label',
        ));

        // AC-07/§23: nothing converted, nothing deleted. The work item still reads 5, and the
        // old values are archived rather than gone.
        $this->assertSame($values['5']->id, $ws->run(fn () => WorkItem::find($item->id)->estimate_value_id));
        $this->assertSame('5', $this->workItemCard($owner, $project, $item)['estimate']['label']);
        $this->assertTrue($ws->run(fn () => EstimateValue::whereKey($values['5']->id)->exists()));
    }

    // ================= AC-05 / AC-06: disable and re-enable =================

    public function test_disabling_keeps_the_system_and_every_estimate(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->configured($owner, $ws);
        $values = $this->values($ws, $project);
        $item = $this->workItem($owner, $project, 'Build the login page');
        $this->assign($owner, $project, $item, $values['5']->id)->assertOk();

        // §24: switching off asks first, once there is something to preserve.
        $this->actingAs($owner)->postJson(route('projects.settings.features.toggle', $project), [
            'feature' => 'estimates', 'enabled' => false,
        ])->assertStatus(409)->assertJsonPath('confirm', true);

        $this->toggle($owner, $project, false);

        // AC-05/§25: the configuration, the values and the assignment all survive.
        $this->assertTrue($ws->run(fn () => ProjectEstimation::where('project_id', $project->id)->exists()));
        $this->assertSame(6, $ws->run(fn () => EstimateValue::whereIn('id', collect($values)->pluck('id'))->count()));
        $this->assertSame($values['5']->id, $ws->run(fn () => WorkItem::find($item->id)->estimate_value_id));

        // §26: the property leaves the forms…
        $b = $this->workItemBootstrap($owner, $project);
        $this->assertFalse($b['estimatesEnabled']);
        $this->assertSame([], $b['estimates']);

        // …and nothing new is accepted while it is off.
        $other = $this->workItem($owner, $project, 'Something else');
        $this->assign($owner, $project, $other, $values['3']->id)->assertStatus(422);

        // §27: but the stored value stays readable on the row it belongs to.
        $this->assertSame('5', $this->workItemCard($owner, $project, $item)['estimate']['label']);

        // AC-06/§28: re-enabling restores the system exactly, with nothing to recreate.
        $this->toggle($owner, $project, true);
        $b = $this->workItemBootstrap($owner, $project);
        $this->assertTrue($b['estimatesEnabled']);
        $this->assertSame(['1', '2', '3', '5', '8', '13'], array_column($b['estimates'], 'label'));
        $this->assign($owner, $project, $other, $values['3']->id)->assertOk();
    }

    // ================= §30: permissions =================

    public function test_only_a_project_admin_configures_estimation(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->configured($owner, $ws);
        $values = $this->values($ws, $project);

        $sam = $this->member($ws, 'member', 'sam@example.com');
        $ws->run(fn () => ProjectMember::create([
            'tenant_id' => $ws->id, 'project_id' => $project->id,
            'user_id' => $sam->id, 'role' => ProjectMember::ROLE_CONTRIBUTOR,
        ]));

        // §30: a Contributor may assign an estimate…
        $item = $this->workItem($owner, $project, 'Build the login page');
        $this->assign($sam, $project, $item, $values['5']->id)->assertOk();

        // …but may not configure the system.
        $this->actingAs($sam)->postJson(route('projects.settings.estimation.values.store', $project), [
            'label' => '21',
        ])->assertStatus(403);
        $this->actingAs($sam)->postJson(route('projects.settings.estimation.configure', $project), [
            'type' => 'category', 'template' => 'tshirt',
        ])->assertStatus(403);
    }

    // ------------------------------------------------------------------ helpers

    private function configured(User $owner, Workspace $ws, string $identifier = 'TESTI', string $name = 'Website Redesign'): Project
    {
        $project = $this->makeProject($owner, $ws, ['identifier' => $identifier, 'name' => $name]);
        $this->actingAs($owner)->get(route('projects.work-items', $project));
        $this->toggle($owner, $project, true);
        $this->configure($owner, $project, 'points', 'fibonacci');

        return $project;
    }

    private function toggle(User $owner, Project $project, bool $enabled): void
    {
        $this->actingAs($owner)->postJson(route('projects.settings.features.toggle', $project), [
            'feature' => 'estimates', 'enabled' => $enabled, 'confirm' => true,
        ])->assertOk();
    }

    /** @return array<string, mixed> */
    private function configure(User $owner, Project $project, string $type, string $template): array
    {
        return $this->actingAs($owner)->postJson(route('projects.settings.estimation.configure', $project), [
            'type' => $type, 'template' => $template,
        ])->assertOk()->json('estimation');
    }

    /** The project's active values, keyed by label. @return array<string, EstimateValue> */
    private function values(Workspace $ws, Project $project): array
    {
        return $ws->run(function () use ($project) {
            $estimation = ProjectEstimation::where('project_id', $project->id)->firstOrFail();

            return $estimation->activeValues()->get()->keyBy('label')->all();
        });
    }

    private function workItem(User $actor, Project $project, string $title): WorkItem
    {
        $id = $this->actingAs($actor)
            ->postJson(route('projects.work-items.store', $project), ['title' => $title])
            ->assertStatus(201)->json('item.id');

        return WorkItem::withoutTenancy()->find($id);
    }

    private function assign(User $actor, Project $project, WorkItem $item, ?int $valueId)
    {
        return $this->actingAs($actor)->patchJson(
            route('projects.work-items.update', ['project' => $project->id, 'workItem' => $item->id]),
            ['estimate_value_id' => $valueId],
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
