<?php

namespace Tests\Feature\Workspace;

use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\ProjectCreator;
use App\Services\WorkspaceAccess;
use App\Services\WorkspaceCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Workspace visibility and access are decided by MEMBERSHIP, never by what is in the database
 * (docs/features/workspace-access-control.md).
 *
 * The scenario throughout: Mike belongs to Workspace 1 and not to Workspace 2. Nothing he can
 * type — a workspace id, a project id from the other workspace, a stale pointer left behind by
 * a removal — may get him in.
 */
class WorkspaceAccessControlTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Workspace, 2: User, 3: Workspace} [mike, ws1, stranger, ws2] */
    private function twoWorkspaces(): array
    {
        $creator = app(WorkspaceCreator::class);

        $mike = User::factory()->create(['email' => 'mike@example.com']);
        $one = $creator->create($mike, ['name' => 'Workspace One', 'slug' => 'ws-one', 'company_size' => '2-10']);

        $stranger = User::factory()->create(['email' => 'stranger@example.com']);
        $two = $creator->create($stranger, ['name' => 'Workspace Two', 'slug' => 'ws-two', 'company_size' => '2-10']);

        return [$mike->fresh(), $one, $stranger->fresh(), $two];
    }

    public function test_only_workspaces_with_an_active_membership_are_visible(): void
    {
        [$mike, $one, , $two] = $this->twoWorkspaces();

        $visible = app(WorkspaceAccess::class)->workspacesFor($mike)->pluck('id')->all();

        $this->assertSame([$one->id], $visible);
        $this->assertNotContains($two->id, $visible);
        // The switcher reads the same relation, so it cannot disagree with the gate.
        $this->assertSame([$one->id], $mike->workspaces()->pluck('tenants.id')->all());
    }

    public function test_a_suspended_membership_stops_being_visible_and_stops_granting_access(): void
    {
        [$mike, $one] = $this->twoWorkspaces();

        $membership = WorkspaceMembership::query()
            ->where('user_id', $mike->id)->where('workspace_id', $one->id)->firstOrFail();
        $membership->forceFill(['status' => WorkspaceMembership::STATUS_DISABLED])->save();

        $this->assertTrue(app(WorkspaceAccess::class)->workspacesFor($mike->fresh())->isEmpty());
        $this->assertFalse($mike->fresh()->can('view', $one));
    }

    public function test_a_pending_invitation_grants_nothing_until_it_is_accepted(): void
    {
        [, $one, $stranger] = $this->twoWorkspaces();

        // An invitation is a `workspace_invitations` row; no membership exists yet, which is
        // exactly why Pending grants nothing — there is no row for a check to have to exclude.
        $this->assertSame(0, WorkspaceMembership::query()
            ->where('user_id', $stranger->id)->where('workspace_id', $one->id)->count());
        $this->assertFalse($stranger->can('view', $one));
    }

    public function test_a_removed_member_cannot_keep_working_from_a_stale_pointer(): void
    {
        [$mike, $one] = $this->twoWorkspaces();

        // The bug this test exists for: `current_workspace_id` still names Workspace One after
        // the membership is gone, and the middleware used to trust it.
        WorkspaceMembership::query()
            ->where('user_id', $mike->id)->where('workspace_id', $one->id)->firstOrFail()->delete();

        $this->actingAs($mike->fresh())
            ->get(route('projects.index'))
            ->assertRedirect(route('onboarding.workspace'));

        // The pointer itself is released at the source, so nothing downstream has to notice.
        $this->assertNull($mike->fresh()->current_workspace_id);
    }

    public function test_losing_one_workspace_moves_you_to_another_you_still_hold(): void
    {
        [$mike, $one] = $this->twoWorkspaces();
        $second = app(WorkspaceCreator::class)->create($mike, [
            'name' => 'Workspace Three', 'slug' => 'ws-three', 'company_size' => '2-10',
        ]);

        WorkspaceMembership::query()
            ->where('user_id', $mike->id)->where('workspace_id', $one->id)->firstOrFail()
            ->forceFill(['status' => WorkspaceMembership::STATUS_DISABLED])->save();

        // Re-aimed at One AFTER the suspension: the stale pointer a long-lived session holds,
        // which is the state the middleware has to refuse rather than trust.
        $mike->forceFill(['current_workspace_id' => $one->id])->save();

        $this->actingAs($mike->fresh())
            ->get(route('projects.index'))
            ->assertRedirect()
            ->assertSessionHas('status', WorkspaceAccess::DENIED_MESSAGE);

        // Moved to the workspace they do still hold — one closed workspace is not a closed
        // account.
        $this->assertSame($second->id, $mike->fresh()->current_workspace_id);
    }

    public function test_a_json_caller_pointed_at_a_workspace_it_lost_gets_403(): void
    {
        [$mike, $one] = $this->twoWorkspaces();

        // Suspended rather than removed, so the pointer survives — the case a stale API client
        // is actually in when access is revoked mid-session.
        WorkspaceMembership::query()
            ->where('user_id', $mike->id)->where('workspace_id', $one->id)->firstOrFail()
            ->forceFill(['status' => WorkspaceMembership::STATUS_DISABLED])->save();

        // `fresh()` first: suspending released the pointer in the database, and re-setting it
        // on a stale instance that still holds the old value writes nothing — Eloquent saves
        // dirty attributes only. Without this the test asserted against a null pointer, which
        // is a different branch entirely.
        $mike = $mike->fresh();
        $mike->forceFill(['current_workspace_id' => $one->id])->save();

        $this->actingAs($mike->fresh())
            ->getJson(route('projects.index'))
            ->assertForbidden();
    }

    public function test_a_child_resource_of_a_foreign_workspace_is_not_reachable_by_id(): void
    {
        [$mike, , $stranger, $two] = $this->twoWorkspaces();

        $project = $two->run(fn () => app(ProjectCreator::class)->create($stranger, $two, [
            'name' => 'Their secret project',
            'identifier' => 'SEC',
            'visibility' => Project::VISIBILITY_PUBLIC,
        ]));

        $this->assertInstanceOf(Project::class, $project);

        // Mike knows the id. Tenancy initializes to HIS workspace, so the tenant-scoped
        // binding simply cannot see it — a 404, which does not confirm it exists.
        $this->actingAs($mike)
            ->get(route('projects.show', $project->id))
            ->assertNotFound();
    }

    public function test_switching_into_a_workspace_you_do_not_belong_to_is_refused(): void
    {
        [$mike, $one, , $two] = $this->twoWorkspaces();

        $this->actingAs($mike)->post(route('workspaces.switch', $two->id))->assertForbidden();

        $this->assertSame($one->id, $mike->fresh()->current_workspace_id);
    }
}
