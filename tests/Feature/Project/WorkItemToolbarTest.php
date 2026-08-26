<?php

namespace Tests\Feature\Project;

use App\Models\ProjectMember;
use App\Models\WorkItem;
use App\Models\WorkItemActivity;
use App\Models\WorkItemComment;
use App\Models\WorkItemSubscriber;
use App\Models\WorkItemVote;
use App\Services\WorkItemCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The work item detail toolbar's vote and subscribe controls (POC html/work-items.html).
 *
 * Two properties carry these: one row per person per item whichever way they click, and both
 * available to anyone who can SEE the item rather than only to whoever may edit it.
 */
class WorkItemToolbarTest extends ProjectTestCase
{
    use RefreshDatabase;

    private function makeItem($ws, $creator, $project, array $data = []): WorkItem
    {
        return $ws->run(fn () => app(WorkItemCreator::class)->create($creator, $project, array_merge([
            'title' => 'Chase the invoice PDF bug',
        ], $data)));
    }

    public function test_voting_switches_sides_and_takes_itself_back(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $item = $this->makeItem($ws, $owner, $project);
        $url = route('projects.work-items.vote', ['project' => $project->id, 'workItem' => $item->id]);

        $this->actingAs($owner)->postJson($url, ['value' => 'up'])
            ->assertOk()
            ->assertJsonPath('votes.up', 1)
            ->assertJsonPath('votes.down', 0)
            ->assertJsonPath('my_vote', 'up');

        // The other side switches rather than adding — a person holds one opinion.
        $this->actingAs($owner)->postJson($url, ['value' => 'down'])
            ->assertOk()
            ->assertJsonPath('votes.up', 0)
            ->assertJsonPath('votes.down', 1)
            ->assertJsonPath('my_vote', 'down');
        $this->assertSame(1, WorkItemVote::where('work_item_id', $item->id)->count());

        // The same side again takes it back.
        $this->actingAs($owner)->postJson($url, ['value' => 'down'])
            ->assertOk()
            ->assertJsonPath('votes.down', 0)
            ->assertJsonPath('my_vote', null);
        $this->assertSame(0, WorkItemVote::where('work_item_id', $item->id)->count());
    }

    public function test_votes_are_counted_across_people_but_owned_individually(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $mate = $this->member($ws, 'admin', 'mate@example.com');
        $item = $this->makeItem($ws, $owner, $project);
        $url = route('projects.work-items.vote', ['project' => $project->id, 'workItem' => $item->id]);

        $this->actingAs($owner)->postJson($url, ['value' => 'up'])->assertOk();
        $this->actingAs($mate)->postJson($url, ['value' => 'up'])->assertOk()->assertJsonPath('votes.up', 2);

        // The count is everyone's; `my_vote` is only ever yours.
        $this->actingAs($owner)->postJson($url, ['value' => 'up'])
            ->assertOk()
            ->assertJsonPath('votes.up', 1)
            ->assertJsonPath('my_vote', null);
        $this->assertSame('up', WorkItemVote::where('user_id', $mate->id)->value('value'));
    }

    public function test_only_up_or_down_is_accepted(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $item = $this->makeItem($ws, $owner, $project);

        $this->actingAs($owner)->postJson(
            route('projects.work-items.vote', ['project' => $project->id, 'workItem' => $item->id]),
            ['value' => 'sideways'],
        )->assertStatus(422);
    }

    public function test_subscribing_toggles_and_is_separate_from_the_projects_own_subscription(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $item = $this->makeItem($ws, $owner, $project);
        $url = route('projects.work-items.subscribe', ['project' => $project->id, 'workItem' => $item->id]);

        $this->actingAs($owner)->postJson($url)->assertOk()->assertJsonPath('subscribed', true);
        $this->assertSame(1, WorkItemSubscriber::where('work_item_id', $item->id)->count());

        // Following one work item is not following the project it lives in.
        $this->assertDatabaseMissing('project_subscribers', ['project_id' => $project->id, 'user_id' => $owner->id]);

        $this->actingAs($owner)->postJson($url)->assertOk()->assertJsonPath('subscribed', false);
        $this->assertSame(0, WorkItemSubscriber::where('work_item_id', $item->id)->count());
    }

    /**
     * The audit trail of a vote: cast, switched, withdrawn — three rows, kept after the vote
     * row itself is gone, and never a comment.
     */
    public function test_every_vote_action_is_recorded_as_an_audit_event(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $item = $this->makeItem($ws, $owner, $project);
        $url = route('projects.work-items.vote', ['project' => $project->id, 'workItem' => $item->id]);

        $this->actingAs($owner)->postJson($url, ['value' => 'up'])->assertOk();
        $this->actingAs($owner)->postJson($url, ['value' => 'down'])->assertOk();
        $this->actingAs($owner)->postJson($url, ['value' => 'down'])->assertOk();

        $rows = WorkItemActivity::query()
            ->where('work_item_id', $item->id)
            ->where('event', WorkItemActivity::EVENT_VOTE_CHANGED)
            ->orderBy('id')
            ->get();

        $this->assertSame(
            [['none', 'up'], ['up', 'down'], ['down', 'none']],
            $rows->map(fn (WorkItemActivity $a) => [$a->old_value, $a->new_value])->all(),
        );
        $this->assertSame('vote', $rows->first()->field);
        $this->assertSame($owner->id, $rows->first()->actor_id);
        // The wording is frozen at write time, which is what History renders before → after.
        $this->assertSame('👍 Up', $rows->first()->meta['new_label']);

        // The current position is gone; the trail is not. And nobody wrote a comment.
        $this->assertSame(0, WorkItemVote::where('work_item_id', $item->id)->count());
        $this->assertSame(0, WorkItemComment::where('work_item_id', $item->id)->count());
    }

    /** The click answers with the rebuilt feed, so an open drawer needs no reload. */
    public function test_the_vote_response_carries_the_activity_and_history_lines(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $item = $this->makeItem($ws, $owner, $project);

        $response = $this->actingAs($owner)->postJson(
            route('projects.work-items.vote', ['project' => $project->id, 'workItem' => $item->id]),
            ['value' => 'up'],
        )->assertOk();

        $feed = $response->json('feed');
        $vote = collect($feed['activity'])->firstWhere('field', 'vote');
        $this->assertNotNull($vote);
        $this->assertSame('none', $vote['old_value']);
        $this->assertSame('up', $vote['new_value']);

        // History keeps it (both sides carry a value), and All merges it into the timeline.
        $this->assertNotNull(collect($feed['history'])->firstWhere('field', 'vote'));
        $this->assertNotNull(collect($feed['all'])->firstWhere('field', 'vote'));
    }

    public function test_a_viewer_may_vote_and_subscribe_without_being_able_to_edit(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $item = $this->makeItem($ws, $owner, $project);
        // A Commenter on this project: they can open the work item and cannot change it,
        // which is exactly the person these two controls exist for.
        $viewer = $this->member($ws, 'member', 'viewer@example.com');
        $ws->run(fn () => ProjectMember::create([
            'project_id' => $project->id, 'user_id' => $viewer->id,
            'role' => ProjectMember::ROLE_COMMENTER,
        ]));

        // Neither changes the work item, so neither is gated on editing it.
        $this->actingAs($viewer)->postJson(
            route('projects.work-items.vote', ['project' => $project->id, 'workItem' => $item->id]),
            ['value' => 'up'],
        )->assertOk()->assertJsonPath('votes.up', 1);

        $this->actingAs($viewer)->postJson(
            route('projects.work-items.subscribe', ['project' => $project->id, 'workItem' => $item->id]),
        )->assertOk()->assertJsonPath('subscribed', true);

        // …and they still cannot edit it.
        $this->actingAs($viewer)->patchJson(
            route('projects.work-items.update', ['project' => $project->id, 'workItem' => $item->id]),
            ['title' => 'Mine now'],
        )->assertForbidden();
    }

    public function test_someone_who_cannot_see_the_item_cannot_vote_on_it(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $item = $this->makeItem($ws, $owner, $project);
        $outsider = $this->member($ws, 'member', 'outsider@example.com');

        // 404, not 403: a refusal must not confirm the work item exists.
        $this->actingAs($outsider)->postJson(
            route('projects.work-items.vote', ['project' => $project->id, 'workItem' => $item->id]),
            ['value' => 'up'],
        )->assertNotFound();

        $this->assertSame(0, WorkItemVote::count());
    }

    public function test_the_row_payload_carries_the_toolbar_state(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $item = $this->makeItem($ws, $owner, $project);

        $this->actingAs($owner)->postJson(
            route('projects.work-items.vote', ['project' => $project->id, 'workItem' => $item->id]),
            ['value' => 'up'],
        )->assertOk();

        // The toolbar has to paint the counts on load, not only after a click.
        $this->actingAs($owner)->get(route('projects.work-items', $project))
            ->assertOk()
            ->assertViewHas('bootstrap', function (array $b) {
                $row = $b['items'][0];

                return $row['votes'] === ['up' => 1, 'down' => 0]
                    && $row['my_vote'] === 'up'
                    && $row['subscribed'] === false;
            });
    }
}
