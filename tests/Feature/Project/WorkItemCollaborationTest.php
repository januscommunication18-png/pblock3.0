<?php

namespace Tests\Feature\Project;

use App\Models\Cycle;
use App\Models\ProjectItemState;
use App\Models\ProjectMember;
use App\Models\WorkItemComment;
use App\Models\WorkItemWorklog;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Work item collaboration: All / Activity / Comments / Updates / Worklogs / Transition /
 * History.
 * Source: ProjectBlock 3.0 — Work Item Activity, Collaboration & Audit Requirements.
 */
class WorkItemCollaborationTest extends ProjectTestCase
{
    use RefreshDatabase;

    /** A workspace member who is also a member of this project (contributor rights). */
    private function projectMember($ws, $project, string $role, string $email)
    {
        $user = $this->member($ws, $role, $email);
        $ws->run(fn () => ProjectMember::create([
            'project_id' => $project->id, 'user_id' => $user->id, 'role' => 'contributor',
        ]));

        return $user;
    }

    private function makeItem($owner, $project, string $title = 'Work'): array
    {
        return $this->actingAs($owner)
            ->postJson(route('projects.work-items.store', $project), ['title' => $title])
            ->assertStatus(201)->json('item');
    }

    private function url(string $name, $project, array $item, array $extra = []): string
    {
        return route($name, array_merge(['project' => $project->id, 'workItem' => $item['id']], $extra));
    }

    public function test_comments_persist_are_sanitized_and_stay_out_of_the_activity_tab(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'TESTI']);
        $this->actingAs($owner)->get(route('projects.work-items', $project));
        $item = $this->makeItem($owner, $project);

        $feed = $this->actingAs($owner)->postJson($this->url('projects.work-items.comments.store', $project, $item), [
            'content' => '<p>API integration is <strong>complete</strong></p><script>alert(1)</script>',
        ])->assertOk()->json('feed');

        // §7: persisted, rich text kept, script dropped (§22.1).
        $this->assertCount(1, $feed['comments']);
        $this->assertStringContainsString('<strong>complete</strong>', $feed['comments'][0]['content']);
        $this->assertStringNotContainsString('<script', $feed['comments'][0]['content']);

        // §6.1/§28.5: the Activity tab is system events only — no comment bodies.
        $this->assertSame([], array_filter($feed['activity'], fn ($a) => str_contains((string) json_encode($a), 'API integration')));

        // Empty and whitespace-only comments are refused (§5.3).
        $this->actingAs($owner)->postJson($this->url('projects.work-items.comments.store', $project, $item), [
            'content' => '<p><br></p>',
        ])->assertStatus(422);
    }

    public function test_a_comment_can_be_edited_and_deleted_only_by_its_author(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'TESTI']);
        $this->actingAs($owner)->get(route('projects.work-items', $project));
        $item = $this->makeItem($owner, $project);

        // Added to the project explicitly: this test is about who owns a comment, not about
        // how project visibility grants reach.
        $other = $this->projectMember($ws, $project, 'member', 'other@example.com');

        $id = $this->actingAs($other)->postJson($this->url('projects.work-items.comments.store', $project, $item), [
            'content' => '<p>Mine</p>',
        ])->assertOk()->json('feed.comments.0.id');

        $editUrl = $this->url('projects.work-items.comments.update', $project, $item, ['comment' => $id]);

        // §7.6: only the author edits.
        $this->actingAs($owner)->patchJson($editUrl, ['content' => '<p>Hijacked</p>'])->assertStatus(403);

        $feed = $this->actingAs($other)->patchJson($editUrl, ['content' => '<p>Mine, revised</p>'])
            ->assertOk()->json('feed');
        $this->assertTrue($feed['comments'][0]['edited']);

        // §7.7: soft-deleted — gone from the conversation, still on the record.
        $this->actingAs($owner)->deleteJson(
            $this->url('projects.work-items.comments.destroy', $project, $item, ['comment' => $id])
        )->assertOk()->assertJsonCount(0, 'feed.comments');

        $this->assertSame(1, $ws->run(fn () => WorkItemComment::withTrashed()->count()));
    }

    public function test_replies_are_one_level_deep(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'TESTI']);
        $this->actingAs($owner)->get(route('projects.work-items', $project));
        $item = $this->makeItem($owner, $project);
        $store = $this->url('projects.work-items.comments.store', $project, $item);

        $rootId = $this->actingAs($owner)->postJson($store, ['content' => '<p>Root</p>'])
            ->assertOk()->json('feed.comments.0.id');

        $replyId = $this->actingAs($owner)->postJson($store, ['content' => '<p>Reply</p>', 'parent_comment_id' => $rootId])
            ->assertOk()->json('feed.comments.0.replies.0.id');

        // §7.8: replying to a reply attaches to its parent rather than nesting deeper.
        $feed = $this->actingAs($owner)->postJson($store, [
            'content' => '<p>Reply to reply</p>', 'parent_comment_id' => $replyId,
        ])->assertOk()->json('feed');

        $this->assertCount(1, $feed['comments']);
        $this->assertCount(2, $feed['comments'][0]['replies']);
    }

    public function test_updates_carry_a_status_and_freeze_subtask_progress(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'TESTI']);
        $this->actingAs($owner)->get(route('projects.work-items', $project));

        $parent = $this->makeItem($owner, $project, 'Parent');
        $childA = $this->makeItem($owner, $project, 'A');
        $childB = $this->makeItem($owner, $project, 'B');

        $this->actingAs($owner)->postJson(
            route('projects.work-items.subtasks.store', ['project' => $project->id, 'workItem' => $parent['id']]),
            ['work_item_ids' => [$childA['id'], $childB['id']]],
        )->assertOk();

        $doneId = $ws->run(fn () => ProjectItemState::where('project_id', $project->id)->where('group', 'completed')->value('id'));
        $this->actingAs($owner)->patchJson(
            route('projects.work-items.update', ['project' => $project->id, 'workItem' => $childA['id']]),
            ['state_id' => $doneId],
        )->assertOk();

        $update = $this->actingAs($owner)->postJson($this->url('projects.work-items.updates.store', $project, $parent), [
            'status' => 'at_risk', 'content' => '<p>Waiting on the vendor</p>',
        ])->assertOk()->json('feed.updates.0');

        $this->assertSame('at_risk', $update['status']);
        $this->assertSame('At Risk', $update['status_label']); // §8.4: never colour alone
        $this->assertSame(['percent' => 50, 'completed' => 1, 'total' => 2], $update['progress']);

        // §8.6: the snapshot is what was reported then, not what is true now.
        $this->actingAs($owner)->patchJson(
            route('projects.work-items.update', ['project' => $project->id, 'workItem' => $childB['id']]),
            ['state_id' => $doneId],
        )->assertOk();

        $after = $this->actingAs($owner)->getJson($this->url('projects.work-items.feed', $project, $parent))
            ->assertOk()->json('feed.updates.0.progress');
        $this->assertSame(50, $after['percent']);

        $this->actingAs($owner)->postJson($this->url('projects.work-items.updates.store', $project, $parent), [
            'status' => 'nonsense', 'content' => '<p>x</p>',
        ])->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_an_update_on_an_item_without_subtasks_reports_no_progress(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'TESTI']);
        $this->actingAs($owner)->get(route('projects.work-items', $project));
        $item = $this->makeItem($owner, $project);

        // §8.6: "0 / 0" says nothing, so there is no progress row at all.
        $this->assertNull($this->actingAs($owner)->postJson($this->url('projects.work-items.updates.store', $project, $item), [
            'status' => 'on_track', 'content' => '<p>Fine</p>',
        ])->assertOk()->json('feed.updates.0.progress'));
    }

    public function test_worklogs_store_minutes_validate_duration_and_total_up(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'TESTI']);
        $this->actingAs($owner)->get(route('projects.work-items', $project));
        $item = $this->makeItem($owner, $project);
        $store = $this->url('projects.work-items.worklogs.store', $project, $item);

        // §9.5: zero duration is not a record of anything.
        $this->actingAs($owner)->postJson($store, ['work_date' => '2026-08-11', 'hours' => 0, 'minutes' => 0])
            ->assertStatus(422);
        $this->actingAs($owner)->postJson($store, ['work_date' => '2026-08-11', 'minutes' => 75])
            ->assertStatus(422)->assertJsonValidationErrors('minutes');

        $feed = $this->actingAs($owner)->postJson($store, [
            'work_date' => '2026-08-11', 'hours' => 2, 'minutes' => 30,
            'description' => 'Completed API integration',
        ])->assertOk()->json('feed');

        // §9.9: stored as total minutes, displayed as hours and minutes.
        $this->assertSame(150, $feed['worklogs']['entries'][0]['minutes']);
        $this->assertSame('2h 30m', $feed['worklogs']['entries'][0]['duration']);
        $this->assertSame('2h 30m', $feed['worklogs']['total_label']);

        $this->actingAs($owner)->postJson($store, ['work_date' => '2026-08-12', 'hours' => 1, 'minutes' => 15])->assertOk();

        // §9.8: the total is recalculated from the rows, not accumulated.
        $total = $this->actingAs($owner)->getJson($this->url('projects.work-items.feed', $project, $item))
            ->json('feed.worklogs');
        $this->assertSame(225, $total['total_minutes']);
        $this->assertSame('3h 45m', $total['total_label']);

        $id = $total['entries'][0]['id'];
        $this->actingAs($owner)->deleteJson($this->url('projects.work-items.worklogs.destroy', $project, $item, ['worklog' => $id]))
            ->assertOk();
        $this->assertSame(150, $this->actingAs($owner)->getJson($this->url('projects.work-items.feed', $project, $item))
            ->json('feed.worklogs.total_minutes'));

        $this->assertSame(1, $ws->run(fn () => WorkItemWorklog::withTrashed()->count() - WorkItemWorklog::count()));
    }

    public function test_someone_elses_worklog_cannot_be_edited_by_a_plain_member(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'TESTI']);
        $this->actingAs($owner)->get(route('projects.work-items', $project));
        $item = $this->makeItem($owner, $project);

        $mate = $this->projectMember($ws, $project, 'member', 'mate@example.com');

        $id = $this->actingAs($owner)->postJson($this->url('projects.work-items.worklogs.store', $project, $item), [
            'work_date' => '2026-08-11', 'hours' => 1,
        ])->assertOk()->json('feed.worklogs.entries.0.id');

        $this->actingAs($mate)->deleteJson(
            $this->url('projects.work-items.worklogs.destroy', $project, $item, ['worklog' => $id])
        )->assertStatus(403);
    }

    public function test_state_changes_produce_transitions_with_time_in_state(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'TESTI']);
        $this->actingAs($owner)->get(route('projects.work-items', $project));
        $item = $this->makeItem($owner, $project);

        [$progressId, $doneId] = $ws->run(fn () => [
            ProjectItemState::where('project_id', $project->id)->where('group', 'started')->value('id'),
            ProjectItemState::where('project_id', $project->id)->where('group', 'completed')->value('id'),
        ]);

        $patch = fn ($state) => $this->actingAs($owner)->patchJson(
            route('projects.work-items.update', ['project' => $project->id, 'workItem' => $item['id']]),
            ['state_id' => $state],
        )->assertOk();

        $patch($progressId);
        $patch($doneId);

        // §10.5: creation seeded the first transition, so the story starts at the beginning.
        $transitions = $this->actingAs($owner)->getJson($this->url('projects.work-items.feed', $project, $item))
            ->assertOk()->json('feed.transition');

        $this->assertCount(3, $transitions);
        $this->assertSame('Done', $transitions[0]['to']);          // newest first
        $this->assertTrue($transitions[0]['is_current']);
        $this->assertSame('In Progress', $transitions[0]['from']);
        $this->assertNull($transitions[2]['from']);                // created into Backlog
        $this->assertNotNull($transitions[0]['duration']);

        // §10.6: a priority change is not a workflow movement.
        $this->actingAs($owner)->patchJson(
            route('projects.work-items.update', ['project' => $project->id, 'workItem' => $item['id']]),
            ['priority' => 'high'],
        )->assertOk();

        $this->assertCount(3, $this->actingAs($owner)->getJson($this->url('projects.work-items.feed', $project, $item))
            ->json('feed.transition'));
    }

    public function test_history_shows_only_before_after_changes_and_all_merges_chronologically(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'TESTI']);
        $this->actingAs($owner)->get(route('projects.work-items', $project));
        $item = $this->makeItem($owner, $project);

        $this->actingAs($owner)->patchJson(
            route('projects.work-items.update', ['project' => $project->id, 'workItem' => $item['id']]),
            ['priority' => 'high'],
        )->assertOk();
        $this->actingAs($owner)->postJson($this->url('projects.work-items.comments.store', $project, $item), [
            'content' => '<p>Discussion</p>',
        ])->assertOk();

        $feed = $this->actingAs($owner)->getJson($this->url('projects.work-items.feed', $project, $item))->json('feed');

        // §11.1: History is the rows that changed a value — "created" is not one of them.
        $historyFields = array_column($feed['history'], 'field');
        $this->assertContains('priority', $historyFields);
        $this->assertSame([], array_filter($feed['history'], fn ($h) => $h['event'] === 'created'));

        // §5.4/§28.3: All merges everything by timestamp and says what each entry is.
        $kinds = array_column($feed['all'], 'kind');
        $this->assertContains('comment', $kinds);
        $this->assertContains('activity', $kinds);

        $times = array_column($feed['all'], 'created_at');
        $sorted = $times;
        rsort($sorted);
        $this->assertSame($sorted, $times, 'The All feed must be ordered by time, not grouped by type.');
    }

    public function test_assignee_history_records_names_on_both_sides_not_ids(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'TESTI']);
        $this->actingAs($owner)->get(route('projects.work-items', $project));
        $item = $this->makeItem($owner, $project);

        $angel = $this->projectMember($ws, $project, 'member', 'angel@example.com');
        $angel->forceFill(['full_name' => 'Angel R. Pritchard'])->save();
        $rohit = $this->projectMember($ws, $project, 'member', 'rohit@example.com');
        $rohit->forceFill(['full_name' => 'Rohit Philip'])->save();

        $url = route('projects.work-items.update', ['project' => $project->id, 'workItem' => $item['id']]);
        $this->actingAs($owner)->patchJson($url, ['assignee_ids' => [$angel->id]])->assertOk();
        $this->actingAs($owner)->patchJson($url, ['assignee_ids' => [$rohit->id]])->assertOk();

        $history = collect($this->actingAs($owner)->getJson($this->url('projects.work-items.feed', $project, $item))
            ->assertOk()->json('feed.history'))
            ->filter(fn ($h) => $h['field'] === 'assignees')
            ->values();

        // §11.6: History reads before → after. Both sides must be resolved at write time —
        // the value columns hold ids, and rendering those gave rows reading "None → 4".
        $swap = $history->first(fn ($h) => $h['old_value'] !== null);
        $this->assertNotNull($swap);
        $this->assertSame(['Angel R. Pritchard'], $swap['meta']['old_labels']);
        $this->assertSame(['Rohit Philip'], $swap['meta']['new_labels']);

        $first = $history->last();
        $this->assertSame([], $first['meta']['old_labels']);
        $this->assertSame(['Angel R. Pritchard'], $first['meta']['new_labels']);
    }

    public function test_the_feed_is_isolated_by_workspace_and_writes_need_permission(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'TESTI']);
        $this->actingAs($owner)->get(route('projects.work-items', $project));
        $item = $this->makeItem($owner, $project);

        // §21: another workspace cannot reach it at all.
        [$outsider] = $this->owner('other-co');
        $this->actingAs($outsider)->getJson($this->url('projects.work-items.feed', $project, $item))->assertNotFound();

        // §22.4: there is no endpoint that writes activity, history or transitions.
        $this->assertFalse(collect(app('router')->getRoutes())->contains(
            fn ($r) => str_contains($r->uri(), 'work-items') && str_contains($r->uri(), 'history')
        ));
    }

    // ================= §8: the At Risk / Off Track label on the list =================

    public function test_a_concerning_update_labels_the_row_and_carries_its_comment(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $item = $this->makeItem($owner, $project, 'Migrate the billing job');

        $this->actingAs($owner)->postJson($this->url('projects.work-items.updates.store', $project, $item), [
            'status' => 'at_risk',
            'content' => '<p>Waiting on the payment provider sandbox. Slipping about three days.</p>',
        ])->assertOk();

        $row = $this->rowFor($owner, $project, $item['id']);

        // The label is what the row shows; the comment is what the tooltip shows, so it has
        // to travel with it as plain text — it goes into an HTML attribute.
        $this->assertSame('at_risk', $row['status_update']['status']);
        $this->assertSame('At Risk', $row['status_update']['label']);
        $this->assertStringContainsString('payment provider sandbox', $row['status_update']['comment']);
        $this->assertStringNotContainsString('<p>', $row['status_update']['comment']);
    }

    public function test_off_track_is_labelled_too_and_on_track_is_not(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $off = $this->makeItem($owner, $project, 'Off track item');
        $fine = $this->makeItem($owner, $project, 'Healthy item');

        $this->actingAs($owner)->postJson($this->url('projects.work-items.updates.store', $project, $off), [
            'status' => 'off_track', 'content' => 'Blocked on a vendor decision.',
        ])->assertOk();

        $this->actingAs($owner)->postJson($this->url('projects.work-items.updates.store', $project, $fine), [
            'status' => 'on_track', 'content' => 'All good.',
        ])->assertOk();

        $this->assertSame('Off Track', $this->rowFor($owner, $project, $off['id'])['status_update']['label']);

        // On Track is the ordinary case. A badge on every row saying "fine" is noise that
        // makes the ones that are NOT fine harder to spot.
        $this->assertNull($this->rowFor($owner, $project, $fine['id'])['status_update']);
    }

    public function test_an_item_that_recovers_stops_being_labelled(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $item = $this->makeItem($owner, $project, 'Recovering item');

        $this->actingAs($owner)->postJson($this->url('projects.work-items.updates.store', $project, $item), [
            'status' => 'off_track', 'content' => 'Badly behind.',
        ])->assertOk();
        $this->assertNotNull($this->rowFor($owner, $project, $item['id'])['status_update']);

        // The CURRENT update decides, not the most recent concerning one — otherwise a row
        // stays flagged forever after the team recovers.
        $this->actingAs($owner)->postJson($this->url('projects.work-items.updates.store', $project, $item), [
            'status' => 'on_track', 'content' => 'Caught up, back on schedule.',
        ])->assertOk();

        $this->assertNull($this->rowFor($owner, $project, $item['id'])['status_update']);
    }

    public function test_the_label_reaches_a_cycles_work_item_row_as_well(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);

        $this->actingAs($owner)->postJson(route('projects.settings.features.toggle', $project), [
            'feature' => 'cycles', 'enabled' => true,
        ])->assertOk();

        $cycle = $ws->run(fn () => Cycle::create([
            'project_id' => $project->id, 'name' => 'Sprint 08',
            'start_date' => now()->toDateString(), 'end_date' => now()->addDays(13)->toDateString(),
        ]));

        $item = $this->makeItem($owner, $project, 'Planned and at risk');
        $this->actingAs($owner)->patchJson(
            route('projects.work-items.update', ['project' => $project->id, 'workItem' => $item['id']]),
            ['cycle_id' => $cycle->id],
        )->assertOk();

        $this->actingAs($owner)->postJson($this->url('projects.work-items.updates.store', $project, $item), [
            'status' => 'at_risk', 'content' => 'Scope grew mid-sprint.',
        ])->assertOk();

        // Both lists render the SAME row component, so both have to be handed the same field.
        $row = collect($this->actingAs($owner)
            ->get(route('projects.cycles.show', ['project' => $project->id, 'cycle' => $cycle->id]))
            ->assertOk()->viewData('bootstrap')['items'])->firstWhere('id', $item['id']);

        $this->assertSame('At Risk', $row['status_update']['label']);
    }

    /** One row out of the work item list, as the grid receives it. */
    private function rowFor($owner, $project, int $itemId): array
    {
        return collect($this->actingAs($owner)->get(route('projects.work-items', $project))
            ->assertOk()->viewData('bootstrap')['items'])->firstWhere('id', $itemId);
    }
}
