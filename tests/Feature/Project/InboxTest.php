<?php

namespace Tests\Feature\Project;

use App\Events\InboxNotificationCreated;
use App\Models\InboxNotification;
use App\Models\Mention;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkItemComment;
use App\Models\WorkspaceMembership;
use App\Services\WorkItemCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

/**
 * The Inbox (docs/features/inbox.md).
 *
 * "Inbox = things that need my attention" (§46) — so most of what is worth testing is when an
 * entry should NOT be there: a duplicate, something you did to yourself, an assignment that
 * was taken away again, a comment that no longer exists.
 */
class InboxTest extends ProjectTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // These write email as well as Inbox rows; the Inbox is what is under test.
        Mail::fake();
    }

    private function makeItem($ws, $creator, $project, array $data = []): WorkItem
    {
        return $ws->run(fn () => app(WorkItemCreator::class)->create($creator, $project, array_merge([
            'title' => 'Chase the invoice PDF bug',
        ], $data)));
    }

    private function collaborator($ws, $project, string $email): User
    {
        $user = $this->member($ws, 'member', $email);
        $ws->run(fn () => ProjectMember::create([
            'project_id' => $project->id, 'user_id' => $user->id, 'role' => ProjectMember::ROLE_CONTRIBUTOR,
        ]));

        return $user;
    }

    private function chip(User $user): string
    {
        return '<span class="pb-mention" data-mention-type="user" data-user-id="'.$user->id.'">@'
            .e($user->displayName()).'</span>';
    }

    private function assign($actor, $project, WorkItem $item, array $userIds): void
    {
        $this->actingAs($actor)->patchJson(
            route('projects.work-items.update', ['project' => $project->id, 'workItem' => $item->id]),
            ['assignee_ids' => $userIds],
        )->assertOk();
    }

    // ---- Assigned (§4.1, §5, §43) --------------------------------------------------------

    public function test_being_assigned_creates_an_unread_entry(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $mate = $this->collaborator($ws, $project, 'mate@example.com');
        $item = $this->makeItem($ws, $owner, $project);

        $this->assign($owner, $project, $item, [$mate->id]);

        $entry = InboxNotification::for($mate->id)->firstOrFail();
        $this->assertSame(InboxNotification::TYPE_ASSIGNMENT, $entry->type);
        $this->assertSame($item->id, $entry->work_item_id);
        $this->assertSame($owner->id, $entry->actor_id);
        $this->assertNull($entry->read_at, 'A new assignment must arrive unread.');
    }

    public function test_assigning_to_yourself_creates_nothing(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $item = $this->makeItem($ws, $owner, $project);

        $this->assign($owner, $project, $item, [$owner->id]);

        $this->assertSame(0, InboxNotification::count());
    }

    public function test_a_second_unread_entry_is_not_created_for_the_same_assignment(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $mate = $this->collaborator($ws, $project, 'mate2@example.com');
        $item = $this->makeItem($ws, $owner, $project);

        // §4.1: off, on again — one unread entry still says the thing the second would.
        $this->assign($owner, $project, $item, [$mate->id]);
        $this->assign($owner, $project, $item, []);
        $this->assign($owner, $project, $item, [$mate->id]);

        $this->assertSame(1, InboxNotification::for($mate->id)->unread()->count());
    }

    public function test_unassigning_removes_the_unread_entry(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $mate = $this->collaborator($ws, $project, 'mate3@example.com');
        $item = $this->makeItem($ws, $owner, $project);

        $this->assign($owner, $project, $item, [$mate->id]);
        $this->assertSame(1, InboxNotification::for($mate->id)->unread()->count());

        // §28: no value in asking somebody to review an assignment that no longer exists.
        $this->assign($owner, $project, $item, []);
        $this->assertSame(0, InboxNotification::for($mate->id)->unread()->count());
    }

    public function test_reassigning_moves_the_entry_to_the_new_assignee(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $rohit = $this->collaborator($ws, $project, 'rohit@example.com');
        $laura = $this->collaborator($ws, $project, 'laura@example.com');
        $item = $this->makeItem($ws, $owner, $project);

        $this->assign($owner, $project, $item, [$rohit->id]);
        $this->assign($owner, $project, $item, [$laura->id]);

        // §29.
        $this->assertSame(0, InboxNotification::for($rohit->id)->unread()->count());
        $this->assertSame(1, InboxNotification::for($laura->id)->unread()->count());
    }

    public function test_a_read_entry_survives_being_unassigned(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $mate = $this->collaborator($ws, $project, 'mate4@example.com');
        $item = $this->makeItem($ws, $owner, $project);

        $this->assign($owner, $project, $item, [$mate->id]);
        $entry = InboxNotification::for($mate->id)->firstOrFail();
        $this->actingAs($mate)->postJson(route('inbox.read', $entry))->assertOk();

        $this->assign($owner, $project, $item, []);

        // §29: once read, it is a record of something that happened. Only UNREAD is cleared.
        $this->assertSame(1, InboxNotification::for($mate->id)->count());
    }

    public function test_reading_an_entry_does_not_remove_the_assignment(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $mate = $this->collaborator($ws, $project, 'mate5@example.com');
        $item = $this->makeItem($ws, $owner, $project);
        $this->assign($owner, $project, $item, [$mate->id]);

        $entry = InboxNotification::for($mate->id)->firstOrFail();
        $this->actingAs($mate)->postJson(route('inbox.read', $entry))->assertOk();

        // §5: "Removing the Inbox entry must not remove the assignment."
        $this->assertTrue($item->fresh()->assignees()->whereKey($mate->id)->exists());
    }

    public function test_an_assignment_row_carries_the_task_description(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $mate = $this->collaborator($ws, $project, 'described@example.com');
        $item = $this->makeItem($ws, $owner, $project, [
            'title' => 'Configure Stale Ticket Automation',
            'description' => '<p>Close tickets with no customer reply after 7 days.</p>',
        ]);

        $this->assign($owner, $project, $item, [$mate->id]);

        // The Inbox shows what the task IS, not only what it is called — the title says what
        // to call it, the first line of the description says whether it is today's problem.
        $row = $this->actingAs($mate)->getJson(route('inbox.list'))->assertOk()->json('items.0');

        expect($row['title'])->toBe('Configure Stale Ticket Automation');
        $this->assertStringContainsString('no customer reply after 7 days', (string) $row['excerpt']);
    }

    // ---- Mentioned (§7, §15-§19) ----------------------------------------------------------

    public function test_being_mentioned_in_a_comment_creates_an_entry_pointing_at_it(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $priya = $this->collaborator($ws, $project, 'priya@example.com');
        $item = $this->makeItem($ws, $owner, $project);

        $this->actingAs($owner)->postJson(
            route('projects.work-items.comments.store', ['project' => $project->id, 'workItem' => $item->id]),
            ['content' => '<p>'.$this->chip($priya).' can you review this?</p>'],
        )->assertOk();

        $comment = WorkItemComment::where('work_item_id', $item->id)->firstOrFail();
        $entry = InboxNotification::for($priya->id)->firstOrFail();

        $this->assertSame(InboxNotification::TYPE_MENTION, $entry->type);
        // §13: the row has to be able to navigate to the comment, not just the item.
        $this->assertSame($comment->id, $entry->comment_id);
        $this->assertSame($item->id, $entry->work_item_id);
        // §12: enough context to understand it without opening it.
        $this->assertStringContainsString('review this', (string) $entry->excerpt);
    }

    public function test_mentioning_yourself_creates_no_entry(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $item = $this->makeItem($ws, $owner, $project, [
            'description' => '<p>'.$this->chip($owner).' remember this.</p>',
        ]);

        // §15. The mention record still exists — only the notification is skipped.
        $this->assertSame(1, Mention::forSource(Mention::SOURCE_WORK_ITEM, $item->id)->count());
        $this->assertSame(0, InboxNotification::count());
    }

    public function test_two_people_named_once_each_get_their_own_entry(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $rohit = $this->collaborator($ws, $project, 'rohit2@example.com');
        $laura = $this->collaborator($ws, $project, 'laura2@example.com');
        $item = $this->makeItem($ws, $owner, $project);

        $this->actingAs($owner)->postJson(
            route('projects.work-items.comments.store', ['project' => $project->id, 'workItem' => $item->id]),
            ['content' => '<p>'.$this->chip($rohit).' '.$this->chip($laura).' please review.</p>'],
        )->assertOk();

        // §16: independent. One reading theirs leaves the other's alone.
        $this->assertSame(1, InboxNotification::for($rohit->id)->unread()->count());
        $this->assertSame(1, InboxNotification::for($laura->id)->unread()->count());

        $entry = InboxNotification::for($rohit->id)->firstOrFail();
        $this->actingAs($rohit)->postJson(route('inbox.read', $entry))->assertOk();
        $this->assertSame(1, InboxNotification::for($laura->id)->unread()->count());
    }

    public function test_deleting_a_comment_clears_its_unread_mentions(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $priya = $this->collaborator($ws, $project, 'priya2@example.com');
        $item = $this->makeItem($ws, $owner, $project);

        $this->actingAs($owner)->postJson(
            route('projects.work-items.comments.store', ['project' => $project->id, 'workItem' => $item->id]),
            ['content' => '<p>'.$this->chip($priya).' review please.</p>'],
        )->assertOk();
        $comment = WorkItemComment::where('work_item_id', $item->id)->firstOrFail();
        $this->assertSame(1, InboxNotification::for($priya->id)->unread()->count());

        $this->actingAs($owner)->deleteJson(route('projects.work-items.comments.destroy', [
            'project' => $project->id, 'workItem' => $item->id, 'comment' => $comment->id,
        ]))->assertOk();

        // §19: nothing left to open.
        $this->assertSame(0, InboxNotification::for($priya->id)->unread()->count());
    }

    public function test_deleting_a_work_item_takes_its_entries_with_it(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $mate = $this->collaborator($ws, $project, 'mate6@example.com');
        $item = $this->makeItem($ws, $owner, $project);
        $this->assign($owner, $project, $item, [$mate->id]);
        $this->assertSame(1, InboxNotification::count());

        $this->actingAs($owner)->deleteJson(
            route('projects.work-items.destroy', ['project' => $project->id, 'workItem' => $item->id]),
        )->assertOk();

        // §30: no invalid unread entries left behind.
        $this->assertSame(0, InboxNotification::count());
    }

    // ---- Real time (§27, CLAUDE.md §12) --------------------------------------------------

    public function test_a_new_notification_is_broadcast_to_its_recipient(): void
    {
        Event::fake([InboxNotificationCreated::class]);
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $mate = $this->collaborator($ws, $project, 'live@example.com');
        $item = $this->makeItem($ws, $owner, $project);

        $this->assign($owner, $project, $item, [$mate->id]);

        Event::assertDispatched(InboxNotificationCreated::class, function ($event) use ($mate, $ws, $item) {
            // Tenant-scoped user channel (§12): a person is in several workspaces, and what
            // they hear about depends on the one they are looking at.
            $channel = $event->broadcastOn()[0];

            return $event->recipientId === $mate->id
                && $event->tenantId === $ws->id
                && $channel->name === "private-tenant.{$ws->id}.user.{$mate->id}"
                // §26/§42: the badge's number and a drawable row — never the work item.
                && $event->counts['all'] === 1
                && $event->card['work_item_id'] === $item->id;
        });
    }

    public function test_nothing_is_broadcast_when_no_notification_is_created(): void
    {
        Event::fake([InboxNotificationCreated::class]);
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $item = $this->makeItem($ws, $owner, $project);

        // Assigning to yourself writes nothing, so it must say nothing on the wire either.
        $this->assign($owner, $project, $item, [$owner->id]);

        Event::assertNotDispatched(InboxNotificationCreated::class);
    }

    public function test_only_you_may_listen_on_your_channel(): void
    {
        [$owner, $ws] = $this->owner();
        $mate = $this->member($ws, 'member', 'listener@example.com');

        $channel = "tenant.{$ws->id}.user.{$mate->id}";

        $this->assertTrue($this->authorizesChannel($mate, $channel));
        // Somebody else's stream, in a workspace they are genuinely in — still refused.
        $this->assertFalse($this->authorizesChannel($owner, $channel));
    }

    public function test_leaving_the_workspace_ends_the_subscription(): void
    {
        [, $ws] = $this->owner();
        $mate = $this->member($ws, 'member', 'departing@example.com');
        $channel = "tenant.{$ws->id}.user.{$mate->id}";

        $this->assertTrue($this->authorizesChannel($mate, $channel));

        // §12: membership is re-checked on subscribe, so an open tab stops receiving that
        // workspace's notifications rather than running on until it is reloaded.
        WorkspaceMembership::where('workspace_id', $ws->id)->where('user_id', $mate->id)->delete();

        $this->assertFalse($this->authorizesChannel($mate->fresh(), $channel));
    }

    /**
     * Ask the REAL broadcast auth endpoint, which is what a browser subscribing does — so this
     * exercises routes/channels.php through the same path Echo uses rather than around it.
     *
     * The broadcaster is switched to `reverb` for the duration. phpunit.xml runs the suite on
     * the `null` connection, whose `auth()` is literally an empty method — under it the
     * endpoint answers 200 to ANY channel name, including ones no callback matches, so a test
     * written against the default would have passed no matter what routes/channels.php said.
     */
    private function authorizesChannel(User $user, string $channel): bool
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);

        // Channel callbacks are registered on the broadcaster that existed at BOOT — the null
        // one — so the connection we just switched to has none of them and refuses everything.
        // Re-running the real file registers them on the current broadcaster, which is what
        // makes this a test of routes/channels.php rather than of the swap.
        require base_path('routes/channels.php');

        return $this->actingAs($user)
            ->postJson('/broadcasting/auth', [
                'channel_name' => 'private-'.$channel,
                'socket_id' => '1234.5678',
            ])
            ->isSuccessful();
    }

    // ---- The screen (§3, §22, §26, §31, §35) ----------------------------------------------

    public function test_the_inbox_lists_unread_entries_with_counts(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $mate = $this->collaborator($ws, $project, 'mate7@example.com');
        $item = $this->makeItem($ws, $owner, $project);
        $this->assign($owner, $project, $item, [$mate->id]);

        $this->actingAs($mate)->get(route('inbox.index'))
            ->assertOk()
            ->assertViewHas('bootstrap', function (array $b) {
                // §22/§26: per-stream counts, and the combined one the badge shows.
                return $b['counts']['assignment'] === 1
                    && $b['counts']['mention'] === 0
                    && $b['counts']['all'] === 1
                    && count($b['items']) === 1;
            });
    }

    public function test_the_streams_are_separate(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $mate = $this->collaborator($ws, $project, 'mate8@example.com');
        $item = $this->makeItem($ws, $owner, $project);

        // §21: assignment and mention are different events and both may be present.
        $this->assign($owner, $project, $item, [$mate->id]);
        $this->actingAs($owner)->postJson(
            route('projects.work-items.comments.store', ['project' => $project->id, 'workItem' => $item->id]),
            ['content' => '<p>'.$this->chip($mate).' and also this.</p>'],
        )->assertOk();

        $assigned = $this->actingAs($mate)->getJson(route('inbox.list').'?tab=assignment')->assertOk();
        $mentions = $this->actingAs($mate)->getJson(route('inbox.list').'?tab=mention')->assertOk();

        $this->assertCount(1, $assigned->json('items'));
        $this->assertCount(1, $mentions->json('items'));
        $this->assertSame(2, $assigned->json('counts.all'));
    }

    public function test_search_finds_a_row_by_work_item_identifier(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $mate = $this->collaborator($ws, $project, 'mate9@example.com');
        $item = $this->makeItem($ws, $owner, $project, ['title' => 'Configure Stale Ticket Automation']);
        $this->assign($owner, $project, $item, [$mate->id]);

        // §24.
        $hit = $this->actingAs($mate)->getJson(route('inbox.list').'?search='.$item->identifier)->assertOk();
        $this->assertCount(1, $hit->json('items'));

        $miss = $this->actingAs($mate)->getJson(route('inbox.list').'?search=nothing-like-this')->assertOk();
        $this->assertCount(0, $miss->json('items'));
    }

    public function test_mark_all_as_read_clears_the_stream(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $mate = $this->collaborator($ws, $project, 'mate10@example.com');

        foreach (['One', 'Two'] as $title) {
            $item = $this->makeItem($ws, $owner, $project, ['title' => $title]);
            $this->assign($owner, $project, $item, [$mate->id]);
        }
        $this->assertSame(2, InboxNotification::for($mate->id)->unread()->count());

        // §35.
        $this->actingAs($mate)->postJson(route('inbox.read-all'), ['tab' => 'all'])
            ->assertOk()
            ->assertJsonPath('counts.all', 0);

        $this->assertSame(0, InboxNotification::for($mate->id)->unread()->count());
    }

    public function test_one_person_cannot_read_another_persons_notification(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $mate = $this->collaborator($ws, $project, 'mate11@example.com');
        $nosy = $this->collaborator($ws, $project, 'nosy@example.com');
        $item = $this->makeItem($ws, $owner, $project);
        $this->assign($owner, $project, $item, [$mate->id]);

        $entry = InboxNotification::for($mate->id)->firstOrFail();

        // 404, not 403: a refusal must not confirm somebody else's notification exists.
        $this->actingAs($nosy)->postJson(route('inbox.read', $entry))->assertNotFound();
        $this->assertNull($entry->fresh()->read_at);
    }

    public function test_a_row_says_when_access_has_since_been_withdrawn(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['visibility' => 'private']);
        $mate = $this->collaborator($ws, $project, 'mate12@example.com');
        $item = $this->makeItem($ws, $owner, $project);
        $this->assign($owner, $project, $item, [$mate->id]);

        $before = $this->actingAs($mate)->getJson(route('inbox.list'))->assertOk()->json('items.0.readable');
        $this->assertTrue($before);

        // Taken off the project after the notification was written (§31).
        $ws->run(fn () => ProjectMember::where('project_id', $project->id)->where('user_id', $mate->id)->delete());

        $after = $this->actingAs($mate->fresh())->getJson(route('inbox.list'))->assertOk()->json('items.0.readable');
        $this->assertFalse($after, 'A stale row must not become a way into content access was removed from.');
    }
}
