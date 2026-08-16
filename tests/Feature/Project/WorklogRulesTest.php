<?php

namespace Tests\Feature\Project;

use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkItemWorklog;
use App\Models\Workspace;
use App\Services\ProjectItemStateProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

/**
 * Who may log time, and against which dates (§9.4).
 *
 * Both rules are enforced on the server, not by hiding the button: the endpoint is reachable
 * without the UI, and hours attached to the wrong person or the wrong week are exactly the
 * figures a capacity report cannot explain afterwards.
 */
class WorklogRulesTest extends ProjectTestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private Project $project;

    private User $owner;

    private function setUpProject(): void
    {
        [$this->owner, $this->workspace] = $this->owner();
        tenancy()->initialize($this->workspace);

        $this->project = $this->makeProject($this->owner, $this->workspace);
        app(ProjectItemStateProvisioner::class)->for($this->project);
    }

    /**
     * A workspace member who is also on the project as a contributor.
     *
     * Workspace membership alone does not grant access to a project here, so a plain member
     * would be refused by the project guard long before the worklog rules were reached — and
     * a test that passes for that reason proves nothing about the rules it names.
     */
    private function collaborator(string $email): User
    {
        $user = $this->member($this->workspace, 'member', $email);

        ProjectMember::create([
            'project_id' => $this->project->id,
            'user_id' => $user->id,
            'role' => ProjectMember::ROLE_CONTRIBUTOR,
        ]);

        return $user;
    }

    private function item(array $assignees = [], ?string $start = null): WorkItem
    {
        $item = WorkItem::create([
            'tenant_id' => $this->workspace->id,
            'project_id' => $this->project->id,
            'title' => 'Configure stale ticket automation',
            'created_by' => $this->owner->id,
            'start_date' => $start,
        ]);

        $item->assignees()->sync(array_map(fn (User $u) => $u->id, $assignees));

        return $item->fresh();
    }

    private function log(User $actor, WorkItem $item, array $overrides = []): TestResponse
    {
        return $this->actingAs($actor)->postJson(
            "/projects/{$this->project->id}/work-items/{$item->id}/worklogs",
            array_merge([
                'work_date' => '2026-09-10',
                'hours' => 1,
                'minutes' => 0,
                'description' => 'Work on API service',
            ], $overrides),
        );
    }

    // ---- who may log ---------------------------------------------------------------------

    public function test_an_assignee_can_log_their_own_time(): void
    {
        $this->setUpProject();
        $sarah = $this->collaborator('sarah@example.com');
        $item = $this->item([$sarah]);

        $this->log($sarah, $item)->assertOk();

        $this->assertSame($sarah->id, WorkItemWorklog::query()->first()->user_id);
    }

    public function test_someone_the_item_is_not_assigned_to_cannot_log_against_it(): void
    {
        $this->setUpProject();
        $sarah = $this->collaborator('sarah@example.com');
        $john = $this->collaborator('john@example.com');
        $item = $this->item([$sarah]);

        // John can edit the item — that is not the same as having done the work.
        $this->log($john, $item)->assertForbidden();

        $this->assertSame(0, WorkItemWorklog::query()->count());
    }

    public function test_a_project_lead_can_log_on_behalf_of_an_assignee(): void
    {
        $this->setUpProject();
        $sarah = $this->collaborator('sarah@example.com');
        $item = $this->item([$sarah]);

        // The owner runs the project but is not on the item — §9.4's manager case.
        $this->log($this->owner, $item, ['user_id' => $sarah->id])->assertOk();

        $log = WorkItemWorklog::query()->first();

        $this->assertSame($sarah->id, $log->user_id);
        // Whose time it is, and who recorded it, are different questions.
        $this->assertSame($this->owner->id, $log->created_by);
    }

    public function test_a_project_lead_still_cannot_log_against_a_non_assignee(): void
    {
        $this->setUpProject();
        $sarah = $this->collaborator('sarah@example.com');
        $john = $this->collaborator('john@example.com');
        $item = $this->item([$sarah]);

        // Running the project buys the right to record somebody else's time, not to invent it
        // for a person who was never on the work.
        $this->log($this->owner, $item, ['user_id' => $john->id])->assertForbidden();
    }

    public function test_a_member_cannot_log_on_someone_elses_behalf_by_asking(): void
    {
        $this->setUpProject();
        $sarah = $this->collaborator('sarah@example.com');
        $john = $this->collaborator('john@example.com');
        $item = $this->item([$sarah, $john]);

        // Both are assignees, so John may log his own — but not Sarah's, however the request
        // is shaped.
        $this->log($john, $item, ['user_id' => $sarah->id])->assertForbidden();
    }

    public function test_an_unassigned_work_item_accepts_no_time_at_all(): void
    {
        $this->setUpProject();
        $item = $this->item();

        // There is nobody for the hours to belong to. 422 rather than 403: the request is not
        // forbidden, the work item is simply not ready for it.
        $this->log($this->owner, $item)->assertStatus(422);
    }

    // ---- which dates ----------------------------------------------------------------------

    public function test_time_cannot_be_logged_before_the_work_item_starts(): void
    {
        $this->setUpProject();
        $sarah = $this->collaborator('sarah@example.com');
        $item = $this->item([$sarah], start: '2026-09-07');

        $this->log($sarah, $item, ['work_date' => '2026-09-06'])
            ->assertStatus(422)
            ->assertSee('before this work item starts', false);
    }

    public function test_time_can_be_logged_on_the_start_date_itself(): void
    {
        $this->setUpProject();
        $sarah = $this->collaborator('sarah@example.com');
        $item = $this->item([$sarah], start: '2026-09-07');

        // Day one is an ordinary day to have worked. An exclusive bound here would be an
        // off-by-one nobody would report as a bug — they would just retype the date.
        $this->log($sarah, $item, ['work_date' => '2026-09-07'])->assertOk();
    }

    public function test_an_item_with_no_start_date_has_no_lower_bound(): void
    {
        $this->setUpProject();
        $sarah = $this->collaborator('sarah@example.com');
        $item = $this->item([$sarah]);

        $this->log($sarah, $item, ['work_date' => '2020-01-01'])->assertOk();
    }

    public function test_editing_a_worklog_cannot_move_it_before_the_start_date(): void
    {
        $this->setUpProject();
        $sarah = $this->collaborator('sarah@example.com');
        $item = $this->item([$sarah], start: '2026-09-07');

        $this->log($sarah, $item, ['work_date' => '2026-09-09'])->assertOk();
        $log = WorkItemWorklog::query()->first();

        $this->actingAs($sarah)->patchJson(
            "/projects/{$this->project->id}/work-items/{$item->id}/worklogs/{$log->id}",
            ['work_date' => '2026-09-01', 'hours' => 1, 'minutes' => 0],
        )->assertStatus(422);
    }

    // ---- the screen knows the same rules ----------------------------------------------------

    public function test_the_screen_tells_the_client_whether_it_runs_the_project(): void
    {
        $this->setUpProject();
        $member = $this->collaborator('member@example.com');

        // The button is drawn from this. It is not the enforcement — that is above — but a
        // button offered and then refused is its own kind of bug.
        $ownerView = $this->actingAs($this->owner)
            ->get("/projects/{$this->project->id}/work-items")
            ->assertOk()->viewData('bootstrap');

        $memberView = $this->actingAs($member)
            ->get("/projects/{$this->project->id}/work-items")
            ->assertOk()->viewData('bootstrap');

        $this->assertTrue($ownerView['canManageProject']);
        $this->assertFalse($memberView['canManageProject']);
    }
}
