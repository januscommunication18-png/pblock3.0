<?php

namespace Tests\Feature\Project;

use App\Mail\WorkItemBlockedMail;
use App\Models\ProjectItemLabel;
use App\Models\ProjectItemState;
use App\Models\ProjectMember;
use App\Models\WorkItem;
use App\Models\WorkItemRelation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

/**
 * Work item structure: sub-tasks, dependencies, relations and links.
 * Source: ProjectBlock 3.0 — Work Item Collaboration & Relationship Requirements (§19–§41).
 */
class WorkItemStructureTest extends ProjectTestCase
{
    use RefreshDatabase;

    /** Create a work item through the API and return its card. */
    private function makeItem($owner, $project, string $title): array
    {
        return $this->actingAs($owner)
            ->postJson(route('projects.work-items.store', $project), ['title' => $title])
            ->assertStatus(201)->json('item');
    }

    private function structureUrl($project, array $item): string
    {
        return route('projects.work-items.structure', ['project' => $project->id, 'workItem' => $item['id']]);
    }

    public function test_existing_work_items_can_be_attached_and_detached_as_subtasks(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'TESTI']);
        $this->actingAs($owner)->get(route('projects.work-items', $project)); // seed states

        $parent = $this->makeItem($owner, $project, 'Build checkout');
        $childA = $this->makeItem($owner, $project, 'Payment form');
        $childB = $this->makeItem($owner, $project, 'Order summary');

        $response = $this->actingAs($owner)->postJson(
            route('projects.work-items.subtasks.store', ['project' => $project->id, 'workItem' => $parent['id']]),
            ['work_item_ids' => [$childA['id'], $childB['id']]],
        )->assertOk();

        $ids = array_column($response->json('structure.subtasks.items'), 'id');
        $this->assertEqualsCanonicalizing([$childA['id'], $childB['id']], $ids);

        // The child's own parent field is what changed — one source of truth (§21).
        $this->assertSame($parent['id'], $ws->run(fn () => WorkItem::find($childA['id'])->parent_id));

        // Removing the link does NOT delete the work item (§26).
        $this->actingAs($owner)->deleteJson(route('projects.work-items.subtasks.destroy', [
            'project' => $project->id, 'workItem' => $parent['id'], 'child' => $childA['id'],
        ]))->assertOk()->assertJsonCount(1, 'structure.subtasks.items');

        $this->assertTrue($ws->run(fn () => WorkItem::whereKey($childA['id'])->exists()));
        $this->assertNull($ws->run(fn () => WorkItem::find($childA['id'])->parent_id));
    }

    public function test_subtasks_cannot_be_self_referential_or_circular(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'TESTI']);
        $this->actingAs($owner)->get(route('projects.work-items', $project));

        $a = $this->makeItem($owner, $project, 'A');
        $b = $this->makeItem($owner, $project, 'B');

        $url = fn ($parent) => route('projects.work-items.subtasks.store', ['project' => $project->id, 'workItem' => $parent['id']]);

        // §25 self parenting.
        $this->actingAs($owner)->postJson($url($a), ['work_item_ids' => [$a['id']]])
            ->assertStatus(422)->assertJsonValidationErrors('work_item_ids');

        // A is B's parent; B must not be able to adopt A back.
        $this->actingAs($owner)->postJson($url($a), ['work_item_ids' => [$b['id']]])->assertOk();
        $this->actingAs($owner)->postJson($url($b), ['work_item_ids' => [$a['id']]])
            ->assertStatus(422)->assertJsonValidationErrors('work_item_ids');

        // Attaching the same child twice is a no-op, not an error (§25).
        $this->actingAs($owner)->postJson($url($a), ['work_item_ids' => [$b['id']]])
            ->assertOk()->assertJsonCount(1, 'structure.subtasks.items');
    }

    public function test_subtask_progress_counts_by_state_group_and_excludes_cancelled(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'TESTI']);
        $this->actingAs($owner)->get(route('projects.work-items', $project));

        $parent = $this->makeItem($owner, $project, 'Parent');
        $children = collect(['one', 'two', 'three'])->map(fn ($t) => $this->makeItem($owner, $project, $t));

        $this->actingAs($owner)->postJson(
            route('projects.work-items.subtasks.store', ['project' => $project->id, 'workItem' => $parent['id']]),
            ['work_item_ids' => $children->pluck('id')->all()],
        )->assertOk();

        [$doneId, $cancelledId] = $ws->run(fn () => [
            ProjectItemState::where('project_id', $project->id)->where('group', 'completed')->value('id'),
            ProjectItemState::where('project_id', $project->id)->where('group', 'cancelled')->value('id'),
        ]);

        $patch = fn ($child, $state) => $this->actingAs($owner)->patchJson(
            route('projects.work-items.update', ['project' => $project->id, 'workItem' => $child['id']]),
            ['state_id' => $state],
        )->assertOk();

        $patch($children[0], $doneId);
        $patch($children[1], $cancelledId);

        $progress = $this->actingAs($owner)->getJson($this->structureUrl($project, $parent))
            ->assertOk()->json('structure.subtasks.progress');

        // 3 sub-tasks, one cancelled → it leaves the denominator entirely (§24).
        $this->assertSame(1, $progress['completed']);
        $this->assertSame(2, $progress['total']);
        $this->assertSame(1, $progress['cancelled']);
        $this->assertSame(50, $progress['percent']);
    }

    public function test_blocking_creates_the_inverse_blocked_by_on_the_other_item(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'TESTI']);
        $this->actingAs($owner)->get(route('projects.work-items', $project));

        $api = $this->makeItem($owner, $project, 'Build API');
        $ui = $this->makeItem($owner, $project, 'Build checkout UI');

        $this->actingAs($owner)->postJson(
            route('projects.work-items.relations.store', ['project' => $project->id, 'workItem' => $api['id']]),
            ['relation_type' => 'blocking', 'work_item_ids' => [$ui['id']]],
        )->assertOk();

        // §28: one item blocks, the other shows itself blocked — from a single stored row.
        $fromApi = $this->actingAs($owner)->getJson($this->structureUrl($project, $api))->json('structure.dependencies');
        $this->assertSame([$ui['id']], array_column($fromApi['blocking'], 'id'));
        $this->assertSame([], $fromApi['blocked_by']);

        $fromUi = $this->actingAs($owner)->getJson($this->structureUrl($project, $ui))->json('structure.dependencies');
        $this->assertSame([$api['id']], array_column($fromUi['blocked_by'], 'id'));
        $this->assertSame([], $fromUi['blocking']);

        $this->assertSame(1, $ws->run(fn () => WorkItemRelation::count()), 'Only the canonical direction is stored.');

        // Removing from the OTHER end clears both views (§32).
        $relationId = $fromUi['blocked_by'][0]['relation_id'];
        $this->actingAs($owner)->deleteJson(route('projects.work-items.relations.destroy', [
            'project' => $project->id, 'workItem' => $ui['id'], 'relation' => $relationId,
        ]))->assertOk();

        $this->assertSame([], $this->actingAs($owner)->getJson($this->structureUrl($project, $api))
            ->json('structure.dependencies.blocking'));
    }

    public function test_adding_blocked_by_stores_the_same_row_as_blocking_the_other_way(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'TESTI']);
        $this->actingAs($owner)->get(route('projects.work-items', $project));

        $ui = $this->makeItem($owner, $project, 'UI');
        $api = $this->makeItem($owner, $project, 'API');

        $this->actingAs($owner)->postJson(
            route('projects.work-items.relations.store', ['project' => $project->id, 'workItem' => $ui['id']]),
            ['relation_type' => 'blocked_by', 'work_item_ids' => [$api['id']]],
        )->assertOk();

        $row = $ws->run(fn () => WorkItemRelation::firstOrFail());
        $this->assertSame('blocking', $row->relation_type);
        $this->assertSame($api['id'], $row->work_item_id);
        $this->assertSame($ui['id'], $row->related_work_item_id);
    }

    public function test_a_blocked_work_item_is_flagged_in_the_list(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'TESTI']);
        $this->actingAs($owner)->get(route('projects.work-items', $project));

        $api = $this->makeItem($owner, $project, 'Build API');
        $ui = $this->makeItem($owner, $project, 'Build checkout UI');

        $this->actingAs($owner)->postJson(
            route('projects.work-items.relations.store', ['project' => $project->id, 'workItem' => $api['id']]),
            ['relation_type' => 'blocking', 'work_item_ids' => [$ui['id']]],
        )->assertOk();

        $counts = fn () => collect($this->actingAs($owner)->get(route('projects.work-items', $project))
            ->assertOk()->viewData('bootstrap')['items'])->pluck('blocked_by_count', 'id');

        // Only the item on the receiving end is flagged.
        $this->assertSame(1, $counts()[$ui['id']]);
        $this->assertSame(0, $counts()[$api['id']]);

        // A blocker that is finished is no longer holding anything up (§27).
        $doneId = $ws->run(fn () => ProjectItemState::where('project_id', $project->id)
            ->where('group', 'completed')->value('id'));
        $this->actingAs($owner)->patchJson(
            route('projects.work-items.update', ['project' => $project->id, 'workItem' => $api['id']]),
            ['state_id' => $doneId],
        )->assertOk();

        $this->assertSame(0, $counts()[$ui['id']]);
    }

    public function test_becoming_blocked_emails_the_project_lead_and_the_assignee(): void
    {
        Mail::fake();

        [$owner, $ws] = $this->owner();
        $lead = $this->member($ws, 'member', 'lead@example.com');
        $assignee = $this->member($ws, 'member', 'assignee@example.com');
        $project = $this->makeProject($owner, $ws, ['identifier' => 'TESTI', 'lead_user_id' => $lead->id]);
        $this->actingAs($owner)->get(route('projects.work-items', $project));

        $blocker = $this->makeItem($owner, $project, 'Build API');
        $blocked = $this->makeItem($owner, $project, 'Build checkout UI');

        $this->actingAs($owner)->patchJson(
            route('projects.work-items.update', ['project' => $project->id, 'workItem' => $blocked['id']]),
            ['assignee_ids' => [$assignee->id]],
        )->assertOk();

        Mail::fake(); // ignore the assignment mail; this test is about the blocker
        $this->actingAs($owner)->postJson(
            route('projects.work-items.relations.store', ['project' => $project->id, 'workItem' => $blocked['id']]),
            ['relation_type' => 'blocked_by', 'work_item_ids' => [$blocker['id']]],
        )->assertOk();

        // §58: the two people who have to act on it.
        Mail::assertQueued(WorkItemBlockedMail::class, fn ($mail) => $mail->hasTo('lead@example.com')
            && $mail->identifier === $blocked['identifier']
            && $mail->blockers[0]['identifier'] === $blocker['identifier']);
        Mail::assertQueued(WorkItemBlockedMail::class, fn ($mail) => $mail->hasTo('assignee@example.com'));

        // The owner did it, so the owner is not told about it.
        Mail::assertNotQueued(WorkItemBlockedMail::class, fn ($mail) => $mail->hasTo($owner->email));
    }

    public function test_the_blocking_side_and_plain_relations_send_no_blocked_email(): void
    {
        Mail::fake();

        [$owner, $ws] = $this->owner();
        $lead = $this->member($ws, 'member', 'lead@example.com');
        $project = $this->makeProject($owner, $ws, ['identifier' => 'TESTI', 'lead_user_id' => $lead->id]);
        $this->actingAs($owner)->get(route('projects.work-items', $project));

        $a = $this->makeItem($owner, $project, 'A');
        $b = $this->makeItem($owner, $project, 'B');

        // "Related to" changes nothing about whether work can proceed.
        $this->actingAs($owner)->postJson(
            route('projects.work-items.relations.store', ['project' => $project->id, 'workItem' => $a['id']]),
            ['relation_type' => 'related', 'work_item_ids' => [$b['id']]],
        )->assertOk();

        Mail::assertNotQueued(WorkItemBlockedMail::class);
    }

    public function test_relations_reject_self_and_contradictory_pairs_and_ignore_duplicates(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'TESTI']);
        $this->actingAs($owner)->get(route('projects.work-items', $project));

        $a = $this->makeItem($owner, $project, 'A');
        $b = $this->makeItem($owner, $project, 'B');
        $url = fn ($item) => route('projects.work-items.relations.store', ['project' => $project->id, 'workItem' => $item['id']]);

        // §36 self relation.
        $this->actingAs($owner)->postJson($url($a), ['relation_type' => 'related', 'work_item_ids' => [$a['id']]])
            ->assertStatus(422)->assertJsonValidationErrors('work_item_ids');

        $this->actingAs($owner)->postJson($url($a), ['relation_type' => 'blocking', 'work_item_ids' => [$b['id']]])->assertOk();

        // §31 contradictory: A blocks B, so A cannot also be blocked by B.
        $this->actingAs($owner)->postJson($url($a), ['relation_type' => 'blocked_by', 'work_item_ids' => [$b['id']]])
            ->assertStatus(422)->assertJsonValidationErrors('work_item_ids');

        // §31 duplicate: silently ignored, still one row.
        $this->actingAs($owner)->postJson($url($a), ['relation_type' => 'blocking', 'work_item_ids' => [$b['id']]])->assertOk();
        $this->assertSame(1, $ws->run(fn () => WorkItemRelation::count()));
    }

    public function test_related_is_symmetric_and_visible_from_both_items(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'TESTI']);
        $this->actingAs($owner)->get(route('projects.work-items', $project));

        $login = $this->makeItem($owner, $project, 'Login page');
        $auth = $this->makeItem($owner, $project, 'Auth API');

        $this->actingAs($owner)->postJson(
            route('projects.work-items.relations.store', ['project' => $project->id, 'workItem' => $login['id']]),
            ['relation_type' => 'related', 'work_item_ids' => [$auth['id']]],
        )->assertOk();

        // §33: both items show it, from one row.
        $this->assertSame([$auth['id']], array_column(
            $this->actingAs($owner)->getJson($this->structureUrl($project, $login))->json('structure.relations.related'), 'id'
        ));
        $this->assertSame([$login['id']], array_column(
            $this->actingAs($owner)->getJson($this->structureUrl($project, $auth))->json('structure.relations.related'), 'id'
        ));
        $this->assertSame(1, $ws->run(fn () => WorkItemRelation::count()));

        // Asking again from the other side must not create a mirror row.
        $this->actingAs($owner)->postJson(
            route('projects.work-items.relations.store', ['project' => $project->id, 'workItem' => $auth['id']]),
            ['relation_type' => 'related', 'work_item_ids' => [$login['id']]],
        )->assertOk();
        $this->assertSame(1, $ws->run(fn () => WorkItemRelation::count()));
    }

    public function test_duplicate_of_shows_as_duplicated_by_on_the_canonical_item(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'TESTI']);
        $this->actingAs($owner)->get(route('projects.work-items', $project));

        $copy = $this->makeItem($owner, $project, 'Duplicate report');
        $original = $this->makeItem($owner, $project, 'Original report');

        $this->actingAs($owner)->postJson(
            route('projects.work-items.relations.store', ['project' => $project->id, 'workItem' => $copy['id']]),
            ['relation_type' => 'duplicate_of', 'work_item_ids' => [$original['id']]],
        )->assertOk();

        $this->assertSame([$original['id']], array_column(
            $this->actingAs($owner)->getJson($this->structureUrl($project, $copy))->json('structure.relations.duplicate_of'), 'id'
        ));
        $this->assertSame([$copy['id']], array_column(
            $this->actingAs($owner)->getJson($this->structureUrl($project, $original))->json('structure.relations.duplicated_by'), 'id'
        ));
    }

    public function test_a_label_can_be_created_from_the_work_item_picker(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'TESTI']);
        $this->actingAs($owner)->get(route('projects.work-items', $project));
        $item = $this->makeItem($owner, $project, 'Needs a label');

        $url = route('projects.work-items.labels.store', ['project' => $project->id, 'workItem' => $item['id']]);

        $created = $this->actingAs($owner)->postJson($url, ['name' => 'Needs QA'])->assertOk();
        $label = $created->json('label');
        $this->assertSame('Needs QA', $label['name']);
        $this->assertMatchesRegularExpression('/^#[0-9A-F]{6}$/i', $label['color'], 'A colour is chosen when none is given.');
        $this->assertSame(['Needs QA'], array_column($created->json('labels'), 'name'));

        // The same name again applies the existing label rather than forking the vocabulary.
        $again = $this->actingAs($owner)->postJson($url, ['name' => 'needs qa'])->assertOk();
        $this->assertSame($label['id'], $again->json('label.id'));
        $this->assertCount(1, $again->json('labels'));

        // It belongs to this project only.
        $this->assertSame(1, $ws->run(fn () => ProjectItemLabel::where('project_id', $project->id)->count()));
    }

    public function test_creating_a_label_from_the_picker_needs_contributor_rights(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'TESTI']);
        $this->actingAs($owner)->get(route('projects.work-items', $project));
        $item = $this->makeItem($owner, $project, 'Guarded');

        // A read-only member may open the item but not extend the project's vocabulary.
        $viewer = $this->member($ws, 'viewer', 'viewer@example.com');
        $ws->run(fn () => ProjectMember::create([
            'project_id' => $project->id, 'user_id' => $viewer->id, 'role' => 'guest',
        ]));

        $this->actingAs($viewer)->postJson(
            route('projects.work-items.labels.store', ['project' => $project->id, 'workItem' => $item['id']]),
            ['name' => 'Sneaky'],
        )->assertStatus(403);

        $this->assertSame(0, $ws->run(fn () => ProjectItemLabel::where('project_id', $project->id)->count()));
    }

    public function test_links_are_added_edited_removed_and_javascript_urls_are_rejected(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'TESTI']);
        $this->actingAs($owner)->get(route('projects.work-items', $project));
        $item = $this->makeItem($owner, $project, 'Checkout');

        $store = route('projects.work-items.links.store', ['project' => $project->id, 'workItem' => $item['id']]);

        // §39: a javascript: URL is stored script waiting to be clicked.
        $this->actingAs($owner)->postJson($store, ['url' => 'javascript:alert(1)'])
            ->assertStatus(422)->assertJsonValidationErrors('url');

        // §38: no title falls back to the domain.
        $links = $this->actingAs($owner)->postJson($store, ['url' => 'https://www.figma.com/file/abc'])
            ->assertOk()->json('structure.links');
        $this->assertSame('figma.com', $links[0]['label']);

        $linkId = $links[0]['id'];
        $updated = $this->actingAs($owner)->patchJson(route('projects.work-items.links.update', [
            'project' => $project->id, 'workItem' => $item['id'], 'link' => $linkId,
        ]), ['url' => 'https://www.figma.com/file/abc', 'title' => 'Checkout UX design'])
            ->assertOk()->json('structure.links');
        $this->assertSame('Checkout UX design', $updated[0]['label']);

        $this->actingAs($owner)->deleteJson(route('projects.work-items.links.destroy', [
            'project' => $project->id, 'workItem' => $item['id'], 'link' => $linkId,
        ]))->assertOk()->assertJsonCount(0, 'structure.links');
    }

    public function test_every_structure_change_is_recorded_in_activity(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'TESTI']);
        $this->actingAs($owner)->get(route('projects.work-items', $project));

        $a = $this->makeItem($owner, $project, 'A');
        $b = $this->makeItem($owner, $project, 'B');

        $this->actingAs($owner)->postJson(
            route('projects.work-items.relations.store', ['project' => $project->id, 'workItem' => $a['id']]),
            ['relation_type' => 'blocking', 'work_item_ids' => [$b['id']]],
        )->assertOk();
        $this->actingAs($owner)->postJson(
            route('projects.work-items.links.store', ['project' => $project->id, 'workItem' => $a['id']]),
            ['url' => 'https://example.test/doc'],
        )->assertOk();

        $fields = collect($this->actingAs($owner)->getJson(route('projects.work-items.activity', [
            'project' => $project->id, 'workItem' => $a['id'],
        ]))->json('activity'))->pluck('field')->all();

        // §36/§45: the relation and the link both left a record.
        $this->assertContains('relation_added', $fields);
        $this->assertContains('link_added', $fields);

        // And the OTHER item recorded its own side of the relation.
        $otherFields = collect($this->actingAs($owner)->getJson(route('projects.work-items.activity', [
            'project' => $project->id, 'workItem' => $b['id'],
        ]))->json('activity'))->pluck('field')->all();
        $this->assertContains('relation_added', $otherFields);
    }

    public function test_the_picker_searches_this_project_and_can_widen_to_the_workspace(): void
    {
        [$owner, $ws] = $this->owner();
        $mine = $this->makeProject($owner, $ws, ['identifier' => 'MINE']);
        $other = $this->makeProject($owner, $ws, ['name' => 'Other', 'identifier' => 'OTH']);
        $this->actingAs($owner)->get(route('projects.work-items', $mine));
        $this->actingAs($owner)->get(route('projects.work-items', $other));

        $anchor = $this->makeItem($owner, $mine, 'Anchor');
        $sibling = $this->makeItem($owner, $mine, 'Sibling task');
        $far = $this->makeItem($owner, $other, 'Faraway task');

        $url = route('projects.work-items.search', ['project' => $mine->id, 'workItem' => $anchor['id']]);

        // §57: this project by default…
        $ids = array_column($this->actingAs($owner)->getJson($url.'?q=task')->assertOk()->json('items'), 'id');
        $this->assertSame([$sibling['id']], $ids);

        // …and the whole workspace on request.
        $wide = array_column($this->actingAs($owner)->getJson($url.'?q=task&all_projects=1')->assertOk()->json('items'), 'id');
        $this->assertEqualsCanonicalizing([$sibling['id'], $far['id']], $wide);

        // The anchor never offers itself (§25/§36).
        $all = array_column($this->actingAs($owner)->getJson($url)->assertOk()->json('items'), 'id');
        $this->assertNotContains($anchor['id'], $all);
    }

    public function test_structure_is_isolated_between_workspaces_and_gated_by_permission(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'TESTI']);
        $this->actingAs($owner)->get(route('projects.work-items', $project));
        $item = $this->makeItem($owner, $project, 'Private work');

        // §56: another workspace's member cannot even see it exists.
        [$outsider] = $this->owner('other-co');
        $this->actingAs($outsider)->getJson($this->structureUrl($project, $item))->assertNotFound();

        // §55: a read-only member may look but not change the structure. Added to the
        // project explicitly, so this asserts the read-only/contributor split rather than
        // depending on how visibility grants reach.
        $viewer = $this->member($ws, 'viewer', 'viewer@example.com');
        $ws->run(fn () => ProjectMember::create([
            'project_id' => $project->id, 'user_id' => $viewer->id, 'role' => 'guest',
        ]));
        $this->actingAs($viewer)->getJson($this->structureUrl($project, $item))->assertOk();
        $this->actingAs($viewer)->postJson(
            route('projects.work-items.links.store', ['project' => $project->id, 'workItem' => $item['id']]),
            ['url' => 'https://example.test'],
        )->assertStatus(403);
    }

    public function test_a_relation_from_another_work_item_cannot_be_deleted_through_this_one(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'TESTI']);
        $this->actingAs($owner)->get(route('projects.work-items', $project));

        $a = $this->makeItem($owner, $project, 'A');
        $b = $this->makeItem($owner, $project, 'B');
        $bystander = $this->makeItem($owner, $project, 'Bystander');

        $this->actingAs($owner)->postJson(
            route('projects.work-items.relations.store', ['project' => $project->id, 'workItem' => $a['id']]),
            ['relation_type' => 'related', 'work_item_ids' => [$b['id']]],
        )->assertOk();

        $relationId = $ws->run(fn () => WorkItemRelation::value('id'));

        // §55: the id is valid, but it has nothing to do with the item in the URL.
        $this->actingAs($owner)->deleteJson(route('projects.work-items.relations.destroy', [
            'project' => $project->id, 'workItem' => $bystander['id'], 'relation' => $relationId,
        ]))->assertNotFound();

        $this->assertSame(1, $ws->run(fn () => WorkItemRelation::count()));
    }
}
