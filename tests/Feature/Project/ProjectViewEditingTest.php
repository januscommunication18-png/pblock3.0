<?php

namespace Tests\Feature\Project;

use App\Models\Project;
use App\Models\ProjectItemLabel;
use App\Models\ProjectItemState;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkItemActivity;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Views — inline editing and the permission model.
 *
 * Source: ProjectBlock 3.0 — View Module Requirements §11 and §14; §29's AC-7, AC-8, AC-9 and
 * AC-18. The thread running through all of it: a View never widens access, and grid
 * editability never overrides normal authorization (§11.3).
 */
class ProjectViewEditingTest extends ProjectTestCase
{
    use RefreshDatabase;

    // ================= §11.1, §11.4: the happy path =================

    public function test_an_authorized_user_edits_a_cell_and_it_is_recorded_as_an_ordinary_edit(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->ready($owner, $ws);
        $view = $this->makeView($owner, $project);
        $item = $this->workItem($owner, $project, 'Build API');

        $done = $ws->run(fn () => ProjectItemState::where('project_id', $project->id)
            ->where('group', 'completed')->value('id'));

        $row = $this->edit($owner, $project, $view, $item, 'work_item.state', ['state_id' => $done])
            ->assertOk()->json('row');

        $this->assertSame($done, $row['state_id']);

        // §11.4: "record the change in normal Work Item activity". It is recorded because the
        // edit IS an ordinary work item edit — there is no second write path to remember.
        $this->assertTrue($ws->run(fn () => WorkItemActivity::query()
            ->where('work_item_id', $item)->where('field', 'state')->exists()));
    }

    public function test_every_editable_property_can_be_written_through_a_cell(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->ready($owner, $ws);
        $this->enable($owner, $project, 'labels');
        $view = $this->makeView($owner, $project);
        $item = $this->workItem($owner, $project, 'Build API');

        $label = $ws->run(fn () => ProjectItemLabel::create([
            'project_id' => $project->id, 'name' => 'QA', 'color' => '#22c55e', 'position' => 1,
        ]));

        $this->edit($owner, $project, $view, $item, 'work_item.title', ['title' => 'Build the API'])
            ->assertOk();
        $this->edit($owner, $project, $view, $item, 'work_item.priority', ['priority' => 'high'])
            ->assertOk();
        $this->edit($owner, $project, $view, $item, 'work_item.due_date', ['due_date' => '2026-12-01'])
            ->assertOk();
        $this->edit($owner, $project, $view, $item, 'member.assignees', ['assignee_ids' => [$owner->id]])
            ->assertOk();

        $row = $this->edit($owner, $project, $view, $item, 'label.labels', ['label_ids' => [$label->id]])
            ->assertOk()->json('row');

        $this->assertSame('Build the API', $row['title']);
        $this->assertSame('high', $row['priority']);
        $this->assertSame('2026-12-01', $row['due_date']);
        $this->assertSame([$owner->id], array_column($row['assignees'], 'id'));
        $this->assertSame(['QA'], array_column($row['labels'], 'name'));
    }

    public function test_an_invalid_value_is_rejected_and_nothing_is_written(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->ready($owner, $ws);
        $view = $this->makeView($owner, $project);
        $item = $this->workItem($owner, $project, 'Build API');

        $this->edit($owner, $project, $view, $item, 'work_item.priority', ['priority' => 'catastrophic'])
            ->assertStatus(422)->assertJsonValidationErrors('priority');

        // The rules are the work item's own, inherited rather than restated — so a rule added
        // there reaches the grid without anyone remembering to copy it.
        $this->assertSame('none', $ws->run(fn () => WorkItem::find($item)->priority));
    }

    // ================= §11.3: the six checks =================

    public function test_a_read_only_column_cannot_be_written_even_when_asked_directly(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->ready($owner, $ws);
        $view = $this->makeView($owner, $project);
        $item = $this->workItem($owner, $project, 'Build API');

        // §11.2: the identifier is read-only in the catalog, and no configuration can make it
        // otherwise — `is_editable` is a ceiling, not a grant.
        $column = $this->column($owner, $project, $view, 'work_item.identifier');

        $this->actingAs($owner)->patchJson($this->cellUrl($project, $view, $item), [
            'column_id' => $column['id'], 'title' => 'Renamed through the ID column',
        ])->assertStatus(422)->assertJsonValidationErrors('column_id');
    }

    public function test_a_payload_that_does_not_match_the_named_column_is_refused(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->ready($owner, $ws);
        $view = $this->makeView($owner, $project);
        $item = $this->workItem($owner, $project, 'Build API');

        // Naming an innocuous column while sending a payload for a different field is exactly
        // the gap check 5 exists to close.
        $priority = $this->column($owner, $project, $view, 'work_item.priority');

        $this->actingAs($owner)->patchJson($this->cellUrl($project, $view, $item), [
            'column_id' => $priority['id'], 'title' => 'Renamed through the priority column',
        ])->assertStatus(422)->assertJsonValidationErrors('column_id');

        $this->assertSame('Build API', $ws->run(fn () => WorkItem::find($item)->title));
    }

    public function test_a_cell_whose_feature_was_switched_off_cannot_be_written(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->ready($owner, $ws);
        $this->enable($owner, $project, 'cycles');
        $view = $this->makeView($owner, $project);
        $item = $this->workItem($owner, $project, 'Build API');

        $column = $this->column($owner, $project, $view, 'cycle.name');
        $this->assertTrue($column['editable']);

        $this->disable($owner, $project, 'cycles');

        // §9.3 keeps the column; §11.3 check 5 refuses the write. Both, at once — the
        // configuration survives and is frozen rather than deleted.
        $this->assertFalse($this->column($owner, $project, $view, 'cycle.name')['editable']);

        $this->actingAs($owner)->patchJson($this->cellUrl($project, $view, $item), [
            'column_id' => $column['id'], 'cycle_id' => null,
        ])->assertStatus(422)->assertJsonValidationErrors('column_id');
    }

    public function test_a_column_from_a_different_view_cannot_authorize_an_edit(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->ready($owner, $ws);
        $mine = $this->makeView($owner, $project, 'Mine');
        $other = $this->makeView($owner, $project, 'Other');
        $item = $this->workItem($owner, $project, 'Build API');

        $column = $this->column($owner, $project, $other, 'work_item.priority');

        $this->actingAs($owner)->patchJson($this->cellUrl($project, $mine, $item), [
            'column_id' => $column['id'], 'priority' => 'high',
        ])->assertStatus(422)->assertJsonValidationErrors('column_id');
    }

    public function test_a_work_item_from_another_project_cannot_be_edited_through_this_view(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->ready($owner, $ws);
        $elsewhere = $this->ready($owner, $ws, 'OTHER');
        $view = $this->makeView($owner, $project);
        $foreign = $this->workItem($owner, $elsewhere, 'Somewhere else');

        $column = $this->column($owner, $project, $view, 'work_item.priority');

        // Without this, naming a View you can edit would be a route to any work item you can
        // reach — the View and the item must both belong to the project in the URL.
        $this->actingAs($owner)->patchJson($this->cellUrl($project, $view, $foreign), [
            'column_id' => $column['id'], 'priority' => 'high',
        ])->assertForbidden();
    }

    // ================= §14: the permission split =================

    public function test_editing_data_and_editing_the_view_are_separate_permissions(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->ready($owner, $ws);
        $contributor = $this->projectMember($ws, $project, 'contributor', 'member@example.com');

        $view = $this->makeView($owner, $project, 'Team view', 'project');
        $item = $this->workItem($owner, $project, 'Build API');
        $column = $this->column($owner, $project, $view, 'work_item.priority');

        // §14.2: a contributor may change the DATA through someone else's shared View…
        $this->actingAs($contributor)->patchJson($this->cellUrl($project, $view, $item), [
            'column_id' => $column['id'], 'priority' => 'high',
        ])->assertOk();

        // …and §14.2 is explicit that this "does not automatically allow changing columns".
        $this->actingAs($contributor)->postJson(route('projects.views.columns.store', [
            'project' => $project->id, 'view' => $view['id'],
        ]), ['key' => 'work_item.created_at'])->assertForbidden();

        $this->actingAs($contributor)->putJson(route('projects.views.columns.order', [
            'project' => $project->id, 'view' => $view['id'],
        ]), ['fixed' => [], 'scroll' => []])->assertForbidden();

        // The bootstrap says so too, as two separate answers — the client must not infer one
        // permission from the other.
        $bootstrap = $this->actingAs($contributor)->get(route('projects.views.show', [
            'project' => $project->id, 'view' => $view['id'],
        ]))->assertOk()->viewData('bootstrap');

        $this->assertTrue($bootstrap['canEditData']);
        $this->assertFalse($bootstrap['canEditView']);
    }

    public function test_a_viewer_who_cannot_change_work_items_cannot_change_cells(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->ready($owner, $ws);
        $guest = $this->projectMember($ws, $project, 'guest', 'guest@example.com');

        $view = $this->makeView($owner, $project, 'Team view', 'project');
        $item = $this->workItem($owner, $project, 'Build API');
        $column = $this->column($owner, $project, $view, 'work_item.priority');

        // AC-8: a read-only user cannot modify the underlying data. Refused by the server,
        // not merely hidden by the client.
        $this->actingAs($guest)->patchJson($this->cellUrl($project, $view, $item), [
            'column_id' => $column['id'], 'priority' => 'high',
        ])->assertForbidden();

        $this->assertSame('none', $ws->run(fn () => WorkItem::find($item)->priority));

        $bootstrap = $this->actingAs($guest)->get(route('projects.views.show', [
            'project' => $project->id, 'view' => $view['id'],
        ]))->assertOk()->viewData('bootstrap');

        $this->assertFalse($bootstrap['canEditData']);
    }

    // ================= §11.3: a View never widens access =================

    public function test_rows_honour_a_project_restricted_to_assigned_work_items(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->ready($owner, $ws);
        $member = $this->projectMember($ws, $project, 'contributor', 'member@example.com');

        $mine = $this->workItem($owner, $project, 'Assigned to the member');
        $theirs = $this->workItem($owner, $project, 'Assigned to nobody');

        $view = $this->makeView($owner, $project, 'Team view', 'project');

        $this->actingAs($owner)->patchJson(route('projects.work-items.update', [
            'project' => $project->id, 'workItem' => $mine,
        ]), ['assignee_ids' => [$member->id]])->assertOk();

        // §10 of the Work Items spec restricts the LIST; a View must not become the way
        // around it. The row simply is not there — it is not hidden in the client.
        $ws->run(fn () => $project->forceFill(['work_item_view' => 'assigned'])->save());

        $rows = $this->actingAs($member)->getJson(route('projects.views.rows', [
            'project' => $project->id, 'view' => $view['id'],
        ]))->assertOk()->json('rows');

        $this->assertSame(['Assigned to the member'], array_column($rows, 'title'));
        $this->assertNotContains($theirs, array_column($rows, 'id'));
    }

    public function test_rows_and_cells_are_refused_on_a_view_the_user_cannot_open(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->ready($owner, $ws);
        $other = $this->projectMember($ws, $project, 'admin', 'admin@example.com');

        $private = $this->makeView($owner, $project, 'Mine', 'private');
        $item = $this->workItem($owner, $project, 'Build API');
        $column = $this->column($owner, $project, $private, 'work_item.priority');

        // 404, not 403: the response must not confirm that someone else's private View exists.
        $this->actingAs($other)->getJson(route('projects.views.rows', [
            'project' => $project->id, 'view' => $private['id'],
        ]))->assertNotFound();

        $this->actingAs($other)->patchJson($this->cellUrl($project, $private, $item), [
            'column_id' => $column['id'], 'priority' => 'high',
        ])->assertForbidden();
    }

    // ================= helpers =================

    private function ready(User $owner, Workspace $ws, string $identifier = 'TESTI'): Project
    {
        $project = $this->makeProject($owner, $ws, [
            'identifier' => $identifier, 'name' => 'Project '.$identifier,
        ]);
        $this->enable($owner, $project, 'views');
        $this->actingAs($owner)->get(route('projects.work-items', $project));

        return $project;
    }

    /** @return array<string, mixed> */
    private function makeView(User $actor, Project $project, string $name = 'All work items', string $visibility = 'private'): array
    {
        return $this->actingAs($actor)->postJson(route('projects.views.store', $project), [
            'name' => $name, 'visibility' => $visibility,
        ])->assertStatus(201)->json('view');
    }

    /** @return array<string, mixed> */
    private function column(User $actor, Project $project, array $view, string $key): array
    {
        $columns = $this->actingAs($actor)->get(route('projects.views.show', [
            'project' => $project->id, 'view' => $view['id'],
        ]))->assertOk()->viewData('bootstrap')['columns'];

        $column = collect($columns)->firstWhere('key', $key);

        $this->assertNotNull($column, "the [{$key}] column is not in this view");

        return $column;
    }

    private function cellUrl(Project $project, array $view, int $itemId): string
    {
        return route('projects.views.rows.update', [
            'project' => $project->id, 'view' => $view['id'], 'workItem' => $itemId,
        ]);
    }

    /** @param  array<string, mixed>  $payload */
    private function edit(User $actor, Project $project, array $view, int $itemId, string $key, array $payload)
    {
        $column = $this->column($actor, $project, $view, $key);

        return $this->actingAs($actor)->patchJson(
            $this->cellUrl($project, $view, $itemId),
            ['column_id' => $column['id']] + $payload,
        );
    }

    private function enable(User $owner, Project $project, string $feature): void
    {
        $this->actingAs($owner)->postJson(route('projects.settings.features.toggle', $project), [
            'feature' => $feature, 'enabled' => true,
        ])->assertOk();
    }

    private function disable(User $owner, Project $project, string $feature): void
    {
        $this->actingAs($owner)->postJson(route('projects.settings.features.toggle', $project), [
            'feature' => $feature, 'enabled' => false, 'confirm' => true,
        ])->assertOk();
    }

    private function projectMember(Workspace $ws, Project $project, string $role, string $email): User
    {
        $user = $this->member($ws, 'member', $email);

        $ws->run(fn () => ProjectMember::create([
            'project_id' => $project->id, 'user_id' => $user->id, 'role' => $role,
        ]));

        return $user->fresh();
    }

    private function workItem(User $actor, Project $project, string $title): int
    {
        return (int) $this->actingAs($actor)
            ->postJson(route('projects.work-items.store', $project), ['title' => $title])
            ->assertStatus(201)->json('item.id');
    }
}
