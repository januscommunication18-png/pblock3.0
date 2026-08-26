<?php

namespace Tests\Feature\Workspace;

use App\Models\Account;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\AccountProvisioner;
use App\Services\WorkspaceCreator;
use App\Services\WorkspaceSwitcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tenant (Account) ownership and membership
 * (docs/features/tenant-workspace-ownership.md).
 *
 * The scenario the requirement is written around, end to end: John owns two workspaces under
 * his own account and invites Mike to one of them; Mike later creates workspaces of his own
 * under an account of his own. Mike must end up owning one account, belonging to three
 * workspaces, and never seeing John's second one.
 */
class TenantOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private function creator(): WorkspaceCreator
    {
        return app(WorkspaceCreator::class);
    }

    private function user(string $name, string $email): User
    {
        return User::factory()->create(['name' => $name, 'email' => $email]);
    }

    // ================= §2 / §3: one account per owner =================

    public function test_creating_a_first_workspace_creates_the_account_and_makes_the_creator_its_owner(): void
    {
        $mike = $this->user('Mike', 'mike@example.com');

        $this->assertFalse(app(AccountProvisioner::class)->owns($mike));

        $workspace = $this->creator()->create($mike, [
            'name' => 'Mike Workspace', 'slug' => 'mike-workspace', 'company_size' => '2-10',
        ]);

        $account = $workspace->account;

        $this->assertNotNull($account);
        $this->assertTrue($account->isOwnedBy($mike));
        $this->assertSame($account->id, $mike->fresh()->account?->id);

        // §8: the owner membership is created with the workspace, so ownership and access
        // never have to be reconciled afterwards.
        $membership = WorkspaceMembership::query()
            ->where('workspace_id', $workspace->id)->where('user_id', $mike->id)->firstOrFail();
        $this->assertSame(WorkspaceMembership::ROLE_OWNER, $membership->role);
        $this->assertSame(WorkspaceMembership::STATUS_ACTIVE, $membership->status);
    }

    public function test_further_workspaces_reuse_the_owners_account_instead_of_creating_another(): void
    {
        $mike = $this->user('Mike', 'mike@example.com');

        $first = $this->creator()->create($mike, [
            'name' => 'Mike Workspace', 'slug' => 'mike-workspace', 'company_size' => '2-10',
        ]);
        $second = $this->creator()->create($mike->fresh(), [
            'name' => 'Marketing', 'slug' => 'marketing', 'company_size' => '2-10',
        ]);
        $third = $this->creator()->create($mike->fresh(), [
            'name' => 'Operations', 'slug' => 'operations', 'company_size' => '2-10',
        ]);

        $this->assertSame($first->account_id, $second->account_id);
        $this->assertSame($first->account_id, $third->account_id);
        $this->assertSame(1, Account::query()->where('owner_user_id', $mike->id)->count());
    }

    /**
     * §6: `account_id` is a REAL column.
     *
     * Worth its own assertion because the failure is silent: a Workspace attribute left off
     * `getCustomColumns()` is folded into stancl's `data` JSON, the real column stays null, and
     * the NOT NULL constraint would be the first thing to notice — at insert time, in
     * production.
     */
    public function test_the_account_is_stored_in_its_own_column_not_the_stancl_data_blob(): void
    {
        $mike = $this->user('Mike', 'mike@example.com');
        $workspace = $this->creator()->create($mike, [
            'name' => 'Mike Workspace', 'slug' => 'mike-workspace', 'company_size' => '2-10',
        ]);

        $this->assertDatabaseHas('tenants', [
            'id' => $workspace->id,
            'account_id' => $workspace->account_id,
        ]);
        $this->assertArrayNotHasKey('account_id', (array) ($workspace->fresh()->data ?? []));
    }

    // ================= §20 / §21: invited-only accounts =================

    public function test_an_invitation_alone_never_creates_an_account_for_the_invited_user(): void
    {
        $john = $this->user('John', 'john@example.com');
        $workspace = $this->creator()->create($john, [
            'name' => 'John Workspace', 'slug' => 'john-workspace', 'company_size' => '2-10',
        ]);

        $sarah = $this->user('Sarah', 'sarah@example.com');
        WorkspaceMembership::create([
            'workspace_id' => $workspace->id, 'user_id' => $sarah->id,
            'role' => 'member', 'status' => WorkspaceMembership::STATUS_ACTIVE, 'joined_at' => now(),
        ]);

        // Belonging to somebody else's workspace is not owning anything (§4).
        $this->assertNull($sarah->fresh()->account);
        $this->assertSame($john->id, $workspace->fresh()->account->owner_user_id);
    }

    public function test_an_invited_user_who_later_creates_a_workspace_gets_their_own_account(): void
    {
        $john = $this->user('John', 'john@example.com');
        $johns = $this->creator()->create($john, [
            'name' => 'John Workspace', 'slug' => 'john-workspace', 'company_size' => '2-10',
        ]);

        $sarah = $this->user('Sarah', 'sarah@example.com');
        WorkspaceMembership::create([
            'workspace_id' => $johns->id, 'user_id' => $sarah->id,
            'role' => 'member', 'status' => WorkspaceMembership::STATUS_ACTIVE, 'joined_at' => now(),
        ]);

        $hers = $this->creator()->create($sarah->fresh(), [
            'name' => 'Sarah Workspace', 'slug' => 'sarah-workspace', 'company_size' => '2-10',
        ]);

        $this->assertTrue($hers->account->isOwnedBy($sarah));
        // §4: being invited elsewhere did not move her, and creating her own did not move John.
        $this->assertNotSame($johns->account_id, $hers->account_id);
        $this->assertSame($john->id, $johns->fresh()->account->owner_user_id);
    }

    // ================= §12: My Workspaces / Invited Workspaces =================

    public function test_the_switcher_separates_owned_workspaces_from_invited_ones(): void
    {
        [$mike, $workspaceA] = $this->theScenario();

        $groups = app(WorkspaceSwitcher::class)->groupsFor($mike->fresh());

        $this->assertSame(['mine', 'invited'], array_column($groups, 'key'));
        $this->assertSame(
            ['Mike Operations', 'Mike Workspace'],
            array_column($groups[0]['workspaces'], 'name'),
        );
        $this->assertSame(['Workspace A'], array_column($groups[1]['workspaces'], 'name'));
        $this->assertSame($workspaceA->id, $groups[1]['workspaces'][0]['id']);
    }

    /** §10: the workspace he was never invited to is not in the list at all. */
    public function test_a_workspace_under_the_same_account_as_an_invitation_stays_invisible(): void
    {
        [$mike, , $workspaceB] = $this->theScenario();

        $listed = [];
        foreach (app(WorkspaceSwitcher::class)->groupsFor($mike->fresh()) as $group) {
            $listed = array_merge($listed, array_column($group['workspaces'], 'id'));
        }

        $this->assertNotContains($workspaceB->id, $listed);
        $this->assertCount(3, $listed);
    }

    /**
     * §12: a workspace can be somebody's own by ROLE as well as by account.
     *
     * Handing a workspace over makes its new Owner's copy of the switcher call it theirs,
     * without the account beneath it changing hands.
     */
    public function test_an_owner_membership_counts_as_a_users_own_workspace(): void
    {
        $john = $this->user('John', 'john@example.com');
        $workspace = $this->creator()->create($john, [
            'name' => 'John Workspace', 'slug' => 'john-workspace', 'company_size' => '2-10',
        ]);

        $mike = $this->user('Mike', 'mike@example.com');
        WorkspaceMembership::create([
            'workspace_id' => $workspace->id, 'user_id' => $mike->id,
            'role' => WorkspaceMembership::ROLE_OWNER,
            'status' => WorkspaceMembership::STATUS_ACTIVE, 'joined_at' => now(),
        ]);

        $groups = app(WorkspaceSwitcher::class)->groupsFor($mike->fresh());

        $this->assertSame(['mine'], array_column($groups, 'key'));
        $this->assertSame(['John Workspace'], array_column($groups[0]['workspaces'], 'name'));
        // The account did not move with the role.
        $this->assertSame($john->id, $workspace->fresh()->account->owner_user_id);
    }

    /**
     * The requirement's §25, built once.
     *
     * @return array{0: User, 1: Workspace, 2: Workspace} [mike, workspace A, workspace B]
     */
    private function theScenario(): array
    {
        $john = $this->user('John', 'john@example.com');
        $a = $this->creator()->create($john, [
            'name' => 'Workspace A', 'slug' => 'workspace-a', 'company_size' => '2-10',
        ]);
        $b = $this->creator()->create($john->fresh(), [
            'name' => 'Workspace B', 'slug' => 'workspace-b', 'company_size' => '2-10',
        ]);

        $mike = $this->user('Mike', 'mike@example.com');
        // Invited to A only — never to B, which sits under the very same account.
        WorkspaceMembership::create([
            'workspace_id' => $a->id, 'user_id' => $mike->id,
            'role' => 'member', 'status' => WorkspaceMembership::STATUS_ACTIVE, 'joined_at' => now(),
        ]);

        $this->creator()->create($mike->fresh(), [
            'name' => 'Mike Workspace', 'slug' => 'mike-workspace', 'company_size' => '2-10',
        ]);
        $this->creator()->create($mike->fresh(), [
            'name' => 'Mike Operations', 'slug' => 'mike-operations', 'company_size' => '2-10',
        ]);

        return [$mike, $a, $b];
    }
}
