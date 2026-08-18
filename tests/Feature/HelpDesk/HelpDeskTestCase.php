<?php

namespace Tests\Feature\HelpDesk;

use App\Models\HelpDesk;
use App\Models\HelpDeskInbox;
use App\Models\HelpDeskMember;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\HelpDesk\HelpDeskMemberManager;
use App\Services\HelpDesk\HelpDeskProvisioner;
use App\Services\WorkspaceApps;
use App\Services\WorkspaceCreator;
use Tests\TestCase;

/**
 * Shared setup for the Help Desk feature tests (docs/features/help-desk.md — Phase 1).
 *
 * Data is built through the application's own services rather than by inserting rows, so a
 * test exercises the same creation paths the app does — and a rule added to the manager later
 * cannot be silently skipped by the fixtures that are supposed to obey it.
 */
abstract class HelpDeskTestCase extends TestCase
{
    /** @return array{0: User, 1: Workspace} owner (fresh) + their workspace */
    protected function owner(string $slug = 'acme-inc', array $apps = ['projects', 'helpdesk']): array
    {
        $user = User::factory()->create(['full_name' => 'Rohit', 'email' => "owner-{$slug}@example.com"]);

        $workspace = app(WorkspaceCreator::class)->create($user, [
            'name' => 'Acme Inc', 'slug' => $slug, 'company_size' => '2-10', 'apps' => $apps,
        ]);

        return [$user->fresh(), $workspace];
    }

    /** A workspace member with a workspace role, for whom this workspace is the active one. */
    protected function member(Workspace $workspace, string $role, string $email): User
    {
        $user = User::factory()->create(['full_name' => ucfirst(strtok($email, '@')), 'email' => $email]);

        return $this->join($workspace, $user, $role);
    }

    /** Put an EXISTING user in a workspace — the same person in two workspaces, for isolation tests. */
    protected function join(Workspace $workspace, User $user, string $role = 'member'): User
    {
        WorkspaceMembership::create([
            'workspace_id' => $workspace->id, 'user_id' => $user->id,
            'role' => $role, 'status' => 'active', 'joined_at' => now(),
        ]);
        $user->forceFill(['current_workspace_id' => $workspace->id])->save();

        return $user->fresh();
    }

    protected function enable(Workspace $workspace, bool $on = true): void
    {
        app(WorkspaceApps::class)->sync($workspace, $on ? ['helpdesk'] : []);
    }

    /** The workspace's Help Desk, provisioned the way an administrator's first visit would. */
    protected function helpDesk(Workspace $workspace, ?User $creator = null): HelpDesk
    {
        return $workspace->run(fn () => app(HelpDeskProvisioner::class)->for($workspace, $creator));
    }

    /** Add somebody to the Help Desk through the manager, as the members screen does. */
    protected function addToHelpDesk(
        Workspace $workspace,
        User $actor,
        User $user,
        string $role = HelpDeskMember::ROLE_AGENT,
        array $inboxIds = [],
    ): HelpDeskMember {
        $helpDesk = $this->helpDesk($workspace, $actor);

        return $workspace->run(fn () => app(HelpDeskMemberManager::class)
            ->add($helpDesk, $actor, $user->id, $role, $inboxIds));
    }

    /** The default inbox every provisioned Help Desk gets, provisioning it if need be. */
    protected function firstInbox(Workspace $workspace): HelpDeskInbox
    {
        $this->helpDesk($workspace);

        return $workspace->run(fn () => HelpDeskInbox::query()->orderBy('id')->firstOrFail());
    }

    /** Reload a membership outside any request, soft-deleted ones included. */
    protected function membershipOf(Workspace $workspace, User $user): ?HelpDeskMember
    {
        return $workspace->run(fn () => HelpDeskMember::withTrashed()
            ->where('user_id', $user->id)
            ->first());
    }
}
