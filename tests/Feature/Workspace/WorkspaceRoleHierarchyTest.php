<?php

namespace Tests\Feature\Workspace;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\WorkspaceCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Workspace role hierarchy and owner safeguards
 * (ProjectBlock_3_Workspace_Roles_Ownership_Gap_Remediation §4.2, §11, §12).
 *
 * Owner and Admin were effectively equivalent, so nothing could be reserved to an Owner and an
 * Admin could act on one. A comparable RANK makes every one of those rules a single comparison.
 */
class WorkspaceRoleHierarchyTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    private function setUpWorkspace(): void
    {
        $this->owner = User::factory()->create(['email' => 'owner@example.com']);
        $this->workspace = app(WorkspaceCreator::class)->create($this->owner, [
            'name' => 'Acme Inc', 'slug' => 'acme-inc', 'company_size' => '2-10',
        ]);
        $this->owner = $this->owner->fresh();
    }

    private function member(string $role, string $email): User
    {
        $user = User::factory()->create(['email' => $email]);

        WorkspaceMembership::create([
            'workspace_id' => $this->workspace->id, 'user_id' => $user->id,
            'role' => $role, 'status' => 'active', 'joined_at' => now(),
        ]);
        $user->forceFill(['current_workspace_id' => $this->workspace->id])->save();

        return $user->fresh();
    }

    private function membershipOf(User $user): WorkspaceMembership
    {
        return WorkspaceMembership::query()
            ->where('workspace_id', $this->workspace->id)
            ->where('user_id', $user->id)
            ->firstOrFail();
    }

    // ---- the hierarchy ----------------------------------------------------------------------

    public function test_the_roles_rank_owner_above_admin_above_member_above_guest(): void
    {
        $this->assertGreaterThan(WorkspaceMembership::rankOf('admin'), WorkspaceMembership::rankOf('owner'));
        $this->assertGreaterThan(WorkspaceMembership::rankOf('member'), WorkspaceMembership::rankOf('admin'));
        $this->assertGreaterThan(WorkspaceMembership::rankOf('guest'), WorkspaceMembership::rankOf('member'));

        // Manager and Viewer carry no workspace privileges, so they rank with Member rather
        // than inventing a hierarchy the product does not have.
        $this->assertSame(WorkspaceMembership::rankOf('member'), WorkspaceMembership::rankOf('manager'));
        $this->assertSame(WorkspaceMembership::rankOf('member'), WorkspaceMembership::rankOf('viewer'));

        // An unknown role ranks lowest, never highest — a typo must not become authority.
        $this->assertSame(0, WorkspaceMembership::rankOf('sudo'));
    }

    // ---- who may manage whom ------------------------------------------------------------------

    public function test_an_admin_may_manage_a_member_but_not_another_admin_or_the_owner(): void
    {
        $this->setUpWorkspace();

        $admin = $this->member('admin', 'admin@example.com');
        $other = $this->member('admin', 'admin2@example.com');
        $plain = $this->member('member', 'member@example.com');

        $this->assertTrue($admin->can('manageMember', [$this->workspace, $this->membershipOf($plain)]));
        // Equals do not outrank each other.
        $this->assertFalse($admin->can('manageMember', [$this->workspace, $this->membershipOf($other)]));
        $this->assertFalse($admin->can('manageMember', [$this->workspace, $this->membershipOf($this->owner)]));
    }

    public function test_a_member_may_manage_nobody(): void
    {
        $this->setUpWorkspace();

        $plain = $this->member('member', 'member@example.com');
        $guest = $this->member('guest', 'guest@example.com');

        $this->assertFalse($plain->can('manageMember', [$this->workspace, $this->membershipOf($guest)]));
    }

    public function test_nobody_manages_themselves_through_this_rule(): void
    {
        $this->setUpWorkspace();
        $admin = $this->member('admin', 'admin@example.com');

        // Leaving and self-demotion are their own rules; this one is about acting on others.
        $this->assertFalse($admin->can('manageMember', [$this->workspace, $this->membershipOf($admin)]));
    }

    // ---- the last owner -----------------------------------------------------------------------

    public function test_the_last_owner_cannot_be_managed_by_anyone(): void
    {
        $this->setUpWorkspace();
        $second = $this->member('owner', 'owner2@example.com');

        // Two owners: each may act on the other, because one would remain. Owners are the
        // exception to the strictly-outranks rule — §11 lets them assign every role.
        $this->assertFalse($this->membershipOf($this->owner)->isLastOwner());
        $this->assertTrue($second->can('manageMember', [$this->workspace, $this->membershipOf($this->owner)]));

        $this->membershipOf($second)->delete();

        // One left: untouchable, or the workspace is left with nobody who can transfer or
        // delete it — an unrecoverable state reachable by one careless click.
        $this->assertTrue($this->membershipOf($this->owner)->fresh()->isLastOwner());
        $this->assertFalse($second->can('manageMember', [$this->workspace, $this->membershipOf($this->owner)]));
    }

    // ---- who may hand out which role -----------------------------------------------------------

    public function test_only_an_owner_may_create_another_owner(): void
    {
        $this->setUpWorkspace();
        $admin = $this->member('admin', 'admin@example.com');

        $this->assertTrue($this->owner->can('assignRole', [$this->workspace, 'owner']));
        $this->assertFalse($admin->can('assignRole', [$this->workspace, 'owner']));
    }

    public function test_a_role_can_only_be_handed_out_below_your_own(): void
    {
        $this->setUpWorkspace();
        $admin = $this->member('admin', 'admin@example.com');

        $this->assertTrue($admin->can('assignRole', [$this->workspace, 'member']));
        $this->assertTrue($admin->can('assignRole', [$this->workspace, 'guest']));
        // Not another admin: that is sideways, not below.
        $this->assertFalse($admin->can('assignRole', [$this->workspace, 'admin']));

        $this->assertTrue($this->owner->can('assignRole', [$this->workspace, 'admin']));
    }

    // ---- owner-only actions ----------------------------------------------------------------------

    public function test_deleting_and_transferring_are_owner_only(): void
    {
        $this->setUpWorkspace();
        $admin = $this->member('admin', 'admin@example.com');

        $this->assertTrue($this->owner->can('delete', $this->workspace));
        $this->assertTrue($this->owner->can('transferOwnership', $this->workspace));

        $this->assertFalse($admin->can('delete', $this->workspace));
        $this->assertFalse($admin->can('transferOwnership', $this->workspace));
    }
}
