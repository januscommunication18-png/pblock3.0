<?php

namespace Tests\Feature\Project;

use App\Mail\WorkItemAssignedMail;
use App\Mail\WorkItemStatusChangedMail;
use App\Models\ProjectItemState;
use App\Models\ProjectMember;
use App\Models\WorkItem;
use App\Models\WorkItemSubscriber;
use App\Services\ProjectItemStateProvisioner;
use App\Services\WorkItemCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

/**
 * The two work item email alerts (CLAUDE.md §9).
 *
 *  1. Assignment — the person given the work is told.
 *  2. Status — everyone SUBSCRIBED to the item is told when it moves, with a link back to it.
 *
 * Most of these assert who does NOT get an email, because that is what decides whether people
 * read these or filter them: the person who made the change, and anyone who can no longer see
 * the item.
 */
class WorkItemEmailAlertsTest extends ProjectTestCase
{
    use RefreshDatabase;

    private function makeItem($ws, $creator, $project, array $data = []): WorkItem
    {
        return $ws->run(fn () => app(WorkItemCreator::class)->create($creator, $project, array_merge([
            'title' => 'Chase the invoice PDF bug',
        ], $data)));
    }

    private function states($ws, $project)
    {
        return $ws->run(function () use ($project) {
            app(ProjectItemStateProvisioner::class)->for($project);

            return ProjectItemState::where('project_id', $project->id)->get()->keyBy('group');
        });
    }

    // ---- 1. Assignment -------------------------------------------------------------------

    public function test_assigning_someone_emails_them(): void
    {
        Mail::fake();
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $mate = $this->member($ws, 'admin', 'mate@example.com');
        $item = $this->makeItem($ws, $owner, $project);

        $this->actingAs($owner)->patchJson(
            route('projects.work-items.update', ['project' => $project->id, 'workItem' => $item->id]),
            ['assignee_ids' => [$mate->id]],
        )->assertOk();

        Mail::assertSent(WorkItemAssignedMail::class, fn ($mail) => $mail->hasTo($mate->email));
    }

    public function test_assigning_something_to_yourself_emails_nobody(): void
    {
        Mail::fake();
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $item = $this->makeItem($ws, $owner, $project);

        // You already know.
        $this->actingAs($owner)->patchJson(
            route('projects.work-items.update', ['project' => $project->id, 'workItem' => $item->id]),
            ['assignee_ids' => [$owner->id]],
        )->assertOk();

        Mail::assertNotSent(WorkItemAssignedMail::class);
    }

    public function test_the_assignment_email_carries_the_task_name_and_its_description(): void
    {
        Mail::fake();
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $mate = $this->member($ws, 'admin', 'described@example.com');
        $item = $this->makeItem($ws, $owner, $project, [
            'title' => 'Configure Stale Ticket Automation',
            'description' => '<p>Close tickets with no customer reply after 7 days.</p>',
        ]);

        $this->actingAs($owner)->patchJson(
            route('projects.work-items.update', ['project' => $project->id, 'workItem' => $item->id]),
            ['assignee_ids' => [$mate->id]],
        )->assertOk();

        // An assignment email carrying only a title makes everybody open the app to find out
        // whether it is urgent.
        Mail::assertSent(WorkItemAssignedMail::class, function ($mail) use ($mate) {
            return $mail->hasTo($mate->email)
                && $mail->title === 'Configure Stale Ticket Automation'
                && str_contains((string) $mail->description, 'no customer reply after 7 days');
        });
    }

    public function test_an_assignment_email_without_a_description_simply_omits_it(): void
    {
        Mail::fake();
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $mate = $this->member($ws, 'admin', 'nodesc@example.com');
        $item = $this->makeItem($ws, $owner, $project, ['title' => 'No description here']);

        $this->actingAs($owner)->patchJson(
            route('projects.work-items.update', ['project' => $project->id, 'workItem' => $item->id]),
            ['assignee_ids' => [$mate->id]],
        )->assertOk();

        Mail::assertSent(WorkItemAssignedMail::class, fn ($mail) => $mail->description === null);
    }

    // ---- 2. Status changes ---------------------------------------------------------------

    public function test_a_status_change_emails_subscribers_with_a_link_back_to_the_item(): void
    {
        Mail::fake();
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $watcher = $this->member($ws, 'admin', 'watcher@example.com');
        $states = $this->states($ws, $project);
        $item = $this->makeItem($ws, $owner, $project, ['state_id' => $states['backlog']->id]);

        $this->actingAs($watcher)->postJson(
            route('projects.work-items.subscribe', ['project' => $project->id, 'workItem' => $item->id]),
        )->assertOk();

        $this->actingAs($owner)->patchJson(
            route('projects.work-items.update', ['project' => $project->id, 'workItem' => $item->id]),
            ['state_id' => $states['started']->id],
        )->assertOk();

        Mail::assertSent(WorkItemStatusChangedMail::class, function ($mail) use ($watcher, $project, $item) {
            return $mail->hasTo($watcher->email)
                && $mail->fromState === 'Backlog'
                && $mail->toState === 'In Progress'
                // The whole point of subscribing: it takes you straight back to the item.
                && $mail->url === route('projects.work-items.show', ['project' => $project->id, 'workItem' => $item->id]);
        });
    }

    public function test_the_person_who_moved_it_is_not_emailed(): void
    {
        Mail::fake();
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $states = $this->states($ws, $project);
        $item = $this->makeItem($ws, $owner, $project, ['state_id' => $states['backlog']->id]);

        // Subscribed to their own item, then moved it themselves.
        $this->actingAs($owner)->postJson(
            route('projects.work-items.subscribe', ['project' => $project->id, 'workItem' => $item->id]),
        )->assertOk();

        $this->actingAs($owner)->patchJson(
            route('projects.work-items.update', ['project' => $project->id, 'workItem' => $item->id]),
            ['state_id' => $states['completed']->id],
        )->assertOk();

        Mail::assertNotSent(WorkItemStatusChangedMail::class);
    }

    public function test_only_a_status_change_sends_it(): void
    {
        Mail::fake();
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $watcher = $this->member($ws, 'admin', 'watcher2@example.com');
        $item = $this->makeItem($ws, $owner, $project);

        $this->actingAs($watcher)->postJson(
            route('projects.work-items.subscribe', ['project' => $project->id, 'workItem' => $item->id]),
        )->assertOk();

        // Renaming an item is not a status change; subscribing to every keystroke is how an
        // alert becomes a mail filter.
        $this->actingAs($owner)->patchJson(
            route('projects.work-items.update', ['project' => $project->id, 'workItem' => $item->id]),
            ['title' => 'A better title'],
        )->assertOk();

        Mail::assertNotSent(WorkItemStatusChangedMail::class);
    }

    public function test_someone_who_can_no_longer_see_the_item_stops_being_emailed(): void
    {
        Mail::fake();
        [$owner, $ws] = $this->owner();
        // Private, so membership is what grants sight of it.
        $project = $this->makeProject($owner, $ws, ['visibility' => 'private']);
        $states = $this->states($ws, $project);
        $former = $this->member($ws, 'member', 'former@example.com');
        $membership = $ws->run(fn () => ProjectMember::create([
            'project_id' => $project->id, 'user_id' => $former->id, 'role' => ProjectMember::ROLE_CONTRIBUTOR,
        ]));
        $item = $this->makeItem($ws, $owner, $project, ['state_id' => $states['backlog']->id]);

        $this->actingAs($former)->postJson(
            route('projects.work-items.subscribe', ['project' => $project->id, 'workItem' => $item->id]),
        )->assertOk();

        // Taken off the project. The subscription row survives — and must stop delivering,
        // or a subscription becomes a permanent grant on a project's work (§7).
        $ws->run(fn () => $membership->delete());

        $this->actingAs($owner)->patchJson(
            route('projects.work-items.update', ['project' => $project->id, 'workItem' => $item->id]),
            ['state_id' => $states['completed']->id],
        )->assertOk();

        $this->assertSame(1, WorkItemSubscriber::where('work_item_id', $item->id)->count());
        Mail::assertNotSent(WorkItemStatusChangedMail::class);
    }

    public function test_every_subscriber_is_emailed(): void
    {
        Mail::fake();
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $states = $this->states($ws, $project);
        $item = $this->makeItem($ws, $owner, $project, ['state_id' => $states['backlog']->id]);

        $watchers = collect(['a', 'b', 'c'])->map(function (string $suffix) use ($ws, $project, $item) {
            $user = $this->member($ws, 'admin', "watcher-{$suffix}@example.com");
            $this->actingAs($user)->postJson(
                route('projects.work-items.subscribe', ['project' => $project->id, 'workItem' => $item->id]),
            )->assertOk();

            return $user;
        });

        $this->actingAs($owner)->patchJson(
            route('projects.work-items.update', ['project' => $project->id, 'workItem' => $item->id]),
            ['state_id' => $states['started']->id],
        )->assertOk();

        // One per subscriber, sent immediately rather than queued (product decision — see
        // WorkItemStatusNotifier). This is the count that shows what immediacy costs: the
        // request pays for three SMTP round-trips before it returns.
        Mail::assertSent(WorkItemStatusChangedMail::class, 3);
        foreach ($watchers as $watcher) {
            Mail::assertSent(WorkItemStatusChangedMail::class, fn ($mail) => $mail->hasTo($watcher->email));
        }
    }
}
