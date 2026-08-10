<?php

namespace Tests\Feature\Settings;

use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;

/** Administration > Members (spec §5 / SET-M-*). */
class MembersSettingsTest extends SettingsTestCase
{
    use RefreshDatabase;

    public function test_invite_persists_pending_rows(): void
    {
        [$owner, $workspace] = $this->owner();

        $this->actingAs($owner)->postJson(route('settings.members.invite'), [
            'invites' => [['email' => 'new@company.com', 'role' => 'member']],
        ])->assertOk()->assertJsonPath('sent', 1);

        $this->assertDatabaseHas('workspace_invitations', [
            'tenant_id' => $workspace->id, 'email' => 'new@company.com', 'status' => 'pending',
        ]);
    }

    public function test_owner_role_is_not_invitable(): void
    {
        [$owner] = $this->owner();

        $this->actingAs($owner)->postJson(route('settings.members.invite'), [
            'invites' => [['email' => 'boss@company.com', 'role' => 'owner']],
        ])->assertStatus(422);
    }

    public function test_revoke_pending_invitation(): void
    {
        [$owner, $workspace] = $this->owner();
        $invite = $workspace->run(fn () => WorkspaceInvitation::create([
            'email' => 'p@company.com', 'role' => 'member',
            'token' => hash('sha256', 'x'), 'status' => 'pending',
        ]));

        $this->actingAs($owner)->deleteJson(route('settings.members.revoke', ['invitation' => $invite->id]))->assertOk();

        $this->assertDatabaseHas('workspace_invitations', ['id' => $invite->id, 'status' => 'revoked']);
    }

    public function test_change_member_role(): void
    {
        [$owner, $workspace] = $this->owner();
        $member = $this->member($workspace, 'member', 'm@example.com');
        $membership = WorkspaceMembership::where('user_id', $member->id)->first();

        $this->actingAs($owner)->patchJson(route('settings.members.role', ['membership' => $membership->id]), [
            'role' => 'admin',
        ])->assertOk();

        $this->assertDatabaseHas('workspace_memberships', ['id' => $membership->id, 'role' => 'admin']);
    }

    public function test_owner_membership_is_protected_from_role_change_and_removal(): void
    {
        [$owner, $workspace] = $this->owner();
        $ownerMembership = WorkspaceMembership::where('user_id', $owner->id)->first();

        $this->actingAs($owner)->patchJson(route('settings.members.role', ['membership' => $ownerMembership->id]), [
            'role' => 'admin',
        ])->assertStatus(422);

        $this->actingAs($owner)->deleteJson(route('settings.members.remove', ['membership' => $ownerMembership->id]))
            ->assertStatus(422);

        $this->assertDatabaseHas('workspace_memberships', ['id' => $ownerMembership->id, 'role' => 'owner']);
    }

    public function test_remove_member(): void
    {
        [$owner, $workspace] = $this->owner();
        $member = $this->member($workspace, 'member', 'gone@example.com');
        $membership = WorkspaceMembership::where('user_id', $member->id)->first();

        $this->actingAs($owner)->deleteJson(route('settings.members.remove', ['membership' => $membership->id]))->assertOk();

        $this->assertDatabaseMissing('workspace_memberships', ['id' => $membership->id]);
    }

    public function test_member_cannot_manage_members(): void
    {
        [, $workspace] = $this->owner();
        $member = $this->member($workspace, 'member', 'plain@example.com');

        $this->actingAs($member)->postJson(route('settings.members.invite'), [
            'invites' => [['email' => 'x@company.com', 'role' => 'member']],
        ])->assertForbidden();
    }

    public function test_fresh_workspace_lists_only_the_owner(): void
    {
        [$owner] = $this->owner();

        $this->actingAs($owner)->get(route('settings.members'))
            ->assertOk()
            ->assertViewHas('bootstrap', function (array $b) use ($owner) {
                return count($b['people']) === 1
                    && $b['people'][0]['role'] === 'owner'
                    && $b['people'][0]['user_id'] === $owner->id;
            });
    }

    public function test_members_do_not_leak_across_workspaces(): void
    {
        [$ownerA, $wsA] = $this->owner('ws-a');
        [$ownerB] = $this->owner('ws-b');

        // Add a plain member to workspace A only.
        $this->member($wsA, 'member', 'a-member@example.com');

        // Workspace A: owner + the one member.
        $this->actingAs($ownerA)->get(route('settings.members'))
            ->assertViewHas('bootstrap', fn (array $b) => count($b['people']) === 2);

        // Workspace B: still only its own owner — A's people are not visible.
        $this->actingAs($ownerB)->get(route('settings.members'))
            ->assertViewHas('bootstrap', function (array $b) use ($ownerB) {
                return count($b['people']) === 1 && $b['people'][0]['user_id'] === $ownerB->id;
            });
    }
}
