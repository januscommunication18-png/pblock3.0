<?php

namespace Tests\Feature\Project;

use App\Models\ProjectItemState;
use App\Models\ProjectMember;
use App\Models\WorkItem;
use App\Services\ProjectItemStateProvisioner;
use App\Services\WorkItemCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * Your Work (docs/features/your-work.md) — one person's work items across every project.
 *
 * The property worth most of these assertions is the boundary: this screen reaches across
 * projects, so the one thing it must never become is a way to read work items from a project
 * the user was never added to. Being assigned to a row is not access to it.
 */
class YourWorkTest extends ProjectTestCase
{
    use RefreshDatabase;

    /** Straight through the service, inside the workspace's tenancy context so tenant_id is stamped. */
    private function makeItem($ws, $creator, $project, array $data = []): WorkItem
    {
        return $ws->run(fn () => app(WorkItemCreator::class)->create($creator, $project, array_merge([
            'title' => 'Chase the invoice PDF bug',
        ], $data)));
    }

    public function test_the_sidebar_link_opens_the_screen_with_every_tab(): void
    {
        [$owner] = $this->owner();

        $response = $this->actingAs($owner)->get(route('your-work'))->assertOk();

        // The sidebar link is no longer a placeholder.
        $response->assertSee(route('your-work'), false);
        foreach (['Summary', 'Assigned', 'Created', 'Subscribed', 'Activity'] as $tab) {
            $response->assertSee($tab, false);
        }
    }

    public function test_summary_carries_its_own_payload_and_no_list(): void
    {
        [$owner] = $this->owner();

        $this->actingAs($owner)->get(route('your-work'))
            ->assertOk()
            ->assertViewHas('bootstrap', fn (array $b) => $b['tab'] === 'summary'
                && $b['workItems'] === null
                && $b['summary'] !== null);
    }

    public function test_summary_counts_the_three_totals_and_breaks_down_assigned_work(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        // States are provisioned on first use, so seed them before asking for one.
        $state = $ws->run(function () use ($project) {
            app(ProjectItemStateProvisioner::class)->for($project);

            return ProjectItemState::where('project_id', $project->id)->where('group', 'started')->firstOrFail();
        });

        // Two assigned (one Working on, one with no state), one created for somebody else.
        $this->makeItem($ws, $owner, $project, ['title' => 'A', 'assignee_ids' => [$owner->id], 'priority' => 'urgent', 'state_id' => $state->id]);
        $this->makeItem($ws, $owner, $project, ['title' => 'B', 'assignee_ids' => [$owner->id], 'priority' => 'low']);
        $this->makeItem($ws, $owner, $project, ['title' => 'C', 'priority' => 'urgent']);

        $summary = $this->bootstrap($owner, 'summary')['summary'];

        $this->assertSame(3, $summary['overview']['created']);
        $this->assertSame(2, $summary['overview']['assigned']);
        // Everything below Overview describes ASSIGNED work only (D-Y5), so the item created
        // for somebody else is counted above and absent from both breakdowns.
        $this->assertSame(2, $summary['total']);

        $workload = collect($summary['workload'])->keyBy('key');
        $this->assertSame(1, $workload['started']['count']);
        $this->assertSame(1, $workload['backlog']['count'], 'An item with no state belongs in Backlog.');
        $this->assertSame(0, $workload['completed']['count']);

        $priority = collect($summary['byPriority'])->keyBy('key');
        $this->assertSame(1, $priority['urgent']['count']);
        $this->assertSame(1, $priority['low']['count']);
        $this->assertSame(0, $priority['medium']['count']);
    }

    public function test_summary_keeps_every_slot_even_at_zero(): void
    {
        [$owner] = $this->owner();

        $summary = $this->bootstrap($owner, 'summary')['summary'];

        // A chart whose categories appear and vanish with the data cannot be compared with
        // the same chart yesterday, so empty slots stay — with their label and colour.
        $this->assertCount(5, $summary['workload']);
        $this->assertCount(5, $summary['byPriority']);
        $this->assertSame(0, $summary['total']);

        foreach (array_merge($summary['workload'], $summary['byPriority']) as $slot) {
            $this->assertSame(0, $slot['count']);
            $this->assertNotEmpty($slot['label']);
            $this->assertMatchesRegularExpression('/^#[0-9A-Fa-f]{6}$/', $slot['color']);
        }
    }

    public function test_an_unknown_tab_is_not_found(): void
    {
        [$owner] = $this->owner();

        $this->actingAs($owner)->get(route('your-work', ['tab' => 'invented']))->assertNotFound();
    }

    public function test_assigned_and_created_list_the_right_items(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $mate = $this->member($ws, 'admin', 'mate@example.com');

        $mine = $this->makeItem($ws, $owner, $project, ['title' => 'Mine', 'assignee_ids' => [$owner->id]]);
        $theirs = $this->makeItem($ws, $mate, $project, ['title' => 'Theirs', 'assignee_ids' => [$mate->id]]);

        $assigned = $this->bootstrap($owner, 'assigned');
        $this->assertSame([$mine->id], array_column($assigned['workItems']['items'], 'id'));

        // Created is by author, not by assignee — the two are different questions.
        $created = $this->bootstrap($owner, 'created');
        $this->assertSame([$mine->id], array_column($created['workItems']['items'], 'id'));

        $mateCreated = $this->bootstrap($mate, 'created');
        $this->assertSame([$theirs->id], array_column($mateCreated['workItems']['items'], 'id'));
    }

    public function test_subscribed_lists_work_from_projects_you_subscribe_to(): void
    {
        [$owner, $ws] = $this->owner();
        $followed = $this->makeProject($owner, $ws, ['name' => 'Followed', 'identifier' => 'FOL']);
        $ignored = $this->makeProject($owner, $ws, ['name' => 'Ignored', 'identifier' => 'IGN']);

        $wanted = $this->makeItem($ws, $owner, $followed, ['title' => 'In the followed project']);
        $this->makeItem($ws, $owner, $ignored, ['title' => 'In the ignored one']);

        DB::table('project_subscribers')->insert([
            'tenant_id' => $ws->id, 'project_id' => $followed->id, 'user_id' => $owner->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // §13's project-level subscription, because that is the one this application has
        // (D-Y1) — there is no per-work-item subscribe.
        $bootstrap = $this->bootstrap($owner, 'subscribed');
        $this->assertSame([$wanted->id], array_column($bootstrap['workItems']['items'], 'id'));
    }

    public function test_a_project_you_cannot_open_never_reaches_this_screen(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $outsider = $this->member($ws, 'member', 'outsider@example.com');

        // Assigned to them, in a project they were never added to. Being named on a row is
        // not access to the project it lives in (requirements §7).
        $item = $this->makeItem($ws, $owner, $project, ['assignee_ids' => [$outsider->id]]);

        $bootstrap = $this->bootstrap($outsider, 'assigned');
        $this->assertSame([], $bootstrap['workItems']['items']);
        $this->assertSame(0, $bootstrap['counts']['assigned']);

        // Added to the project, and the same row appears.
        ProjectMember::query()->create([
            'tenant_id' => $ws->id, 'project_id' => $project->id,
            'user_id' => $outsider->id, 'role' => 'admin',
        ]);

        $bootstrap = $this->bootstrap($outsider->fresh(), 'assigned');
        $this->assertSame([$item->id], array_column($bootstrap['workItems']['items'], 'id'));
    }

    public function test_rows_carry_their_project_and_that_projects_own_vocabulary(): void
    {
        [$owner, $ws] = $this->owner();
        $alpha = $this->makeProject($owner, $ws, ['name' => 'Alpha', 'identifier' => 'ALP']);
        $beta = $this->makeProject($owner, $ws, ['name' => 'Beta', 'identifier' => 'BET']);

        $this->makeItem($ws, $owner, $alpha, ['title' => 'In Alpha', 'assignee_ids' => [$owner->id]]);
        $this->makeItem($ws, $owner, $beta, ['title' => 'In Beta', 'assignee_ids' => [$owner->id]]);

        $payload = $this->bootstrap($owner, 'assigned')['workItems'];

        // The extra chip: every row names the project it came from.
        foreach ($payload['items'] as $row) {
            $this->assertNotNull($row['project'], 'A row reached Your Work without its project.');
            $this->assertSame($row['project_id'], $row['project']['id']);
        }
        $this->assertEqualsCanonicalizing(
            ['Alpha', 'Beta'],
            array_column(array_column($payload['items'], 'project'), 'name')
        );

        // And the vocabulary is INDEXED rather than flattened, so a picker on an Alpha row
        // cannot offer Beta's states (or PATCH to Beta's URL).
        $this->assertTrue($payload['multiProject']);
        $this->assertArrayHasKey($alpha->id, $payload['projects']);
        $this->assertArrayHasKey($beta->id, $payload['projects']);

        $alphaStates = array_column($payload['projects'][$alpha->id]['states'], 'id');
        $betaStates = array_column($payload['projects'][$beta->id]['states'], 'id');
        $this->assertNotEmpty($alphaStates);
        $this->assertSame([], array_intersect($alphaStates, $betaStates));

        $this->assertStringContainsString("/projects/{$alpha->id}/", $payload['projects'][$alpha->id]['endpoints']['update']);
        $this->assertStringContainsString("/projects/{$beta->id}/", $payload['projects'][$beta->id]['endpoints']['update']);
    }

    public function test_activity_is_your_own_actions_and_stays_inside_your_reach(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $mate = $this->member($ws, 'admin', 'mate2@example.com');

        $mine = $this->makeItem($ws, $owner, $project, ['title' => 'Mine']);
        $this->makeItem($ws, $mate, $project, ['title' => 'Theirs']);

        // D-Y2: what you did, not what happened to your items.
        $activity = $this->bootstrap($owner, 'activity')['activity'];
        $this->assertNotEmpty($activity);
        foreach ($activity as $entry) {
            $this->assertSame($owner->id, $entry['actor']['id']);
        }
        $this->assertContains($mine->id, array_column(array_column($activity, 'item'), 'id'));

        // A member of the workspace who cannot open the project sees none of its history,
        // even for entries they wrote themselves elsewhere.
        $outsider = $this->member($ws, 'member', 'outsider2@example.com');
        $this->assertSame([], $this->bootstrap($outsider, 'activity')['activity']);
    }

    public function test_assignee_avatars_are_urls_not_disk_paths(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);

        // Profile images live on the private disk, so the column holds a path. Every screen
        // reads this attribute straight into an <img src>, so a path here is a broken image
        // everywhere the person appears — the row chip, member lists, comments, the topbar.
        $owner->forceFill(['avatar_url' => 'account/'.$owner->id.'/photo.png'])->save();

        $this->makeItem($ws, $owner, $project, ['assignee_ids' => [$owner->id]]);

        $row = $this->bootstrap($owner->fresh(), 'assigned')['workItems']['items'][0];
        $avatar = $row['assignees'][0]['avatar_url'];

        $this->assertStringStartsWith('http', $avatar);
        $this->assertStringContainsString('/account/image/'.$owner->id.'/avatar', $avatar);
    }

    public function test_an_avatar_that_is_already_a_url_is_left_alone(): void
    {
        [$owner] = $this->owner();

        // Captured from an OAuth provider, or uploaded before profile images moved disks.
        $owner->forceFill(['avatar_url' => 'https://cdn.example.com/me.png'])->save();

        $this->assertSame('https://cdn.example.com/me.png', $owner->fresh()->avatar_url);
    }

    public function test_counts_agree_with_the_lists_behind_them(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $this->makeItem($ws, $owner, $project, ['title' => 'One', 'assignee_ids' => [$owner->id]]);
        $this->makeItem($ws, $owner, $project, ['title' => 'Two']);

        $bootstrap = $this->bootstrap($owner, 'assigned');
        $this->assertSame(1, $bootstrap['counts']['assigned']);
        $this->assertSame(2, $bootstrap['counts']['created']);
        $this->assertCount($bootstrap['counts']['assigned'], $bootstrap['workItems']['items']);
    }

    /** @return array<string, mixed> */
    private function bootstrap($user, string $tab): array
    {
        return $this->actingAs($user)
            ->get(route('your-work', ['tab' => $tab]))
            ->assertOk()
            ->viewData('bootstrap');
    }
}
