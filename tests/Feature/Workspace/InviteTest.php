<?php

namespace Tests\Feature\Workspace;

use App\Models\OnboardingProfile;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMembership;
use App\Services\WorkspaceCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InviteTest extends TestCase
{
    use RefreshDatabase;

    /** A user who has created their first workspace and is on the invite step. */
    private function userWithWorkspace(): array
    {
        $user = User::factory()->create(['full_name' => 'Rohit', 'email' => 'rohit@example.com']);
        OnboardingProfile::create([
            'user_id' => $user->id,
            'current_step' => OnboardingProfile::STEP_COMPLETED,
            'completed_at' => now(),
        ]);
        $workspace = app(WorkspaceCreator::class)->create($user, [
            'name' => 'Acme Inc', 'slug' => 'acme-inc', 'company_size' => '2-10',
        ]);

        return [$user->fresh(), $workspace];
    }

    /** Invitations are tenant-scoped — count them within the workspace context. */
    private function invitationCountFor(Workspace $workspace): int
    {
        return $workspace->run(fn () => WorkspaceInvitation::count());
    }

    public function test_invite_step_creates_pending_invitations_and_lands_on_welcome(): void
    {
        [$user, $workspace] = $this->userWithWorkspace();

        $response = $this->actingAs($user)->post(route('onboarding.invite.store'), [
            'invites' => [
                ['email' => 'a@company.com', 'role' => 'member'],
                ['email' => 'b@company.com', 'role' => 'admin'],
            ],
        ]);

        $response->assertRedirect(route('welcome'));

        $this->assertSame(2, $this->invitationCountFor($workspace));
        $this->assertDatabaseHas('workspace_invitations', [
            'tenant_id' => $workspace->id, 'email' => 'a@company.com', 'role' => 'member', 'status' => 'pending',
        ]);
        // spec §9: invite step marked complete
        $this->assertNotNull($user->onboardingProfile->fresh()->workspace_setup_completed_at);
    }

    public function test_skip_marks_setup_complete_and_lands_on_welcome(): void
    {
        [$user, $workspace] = $this->userWithWorkspace();

        $this->actingAs($user)->post(route('onboarding.invite.skip'))
            ->assertRedirect(route('welcome'));

        $this->assertSame(0, $this->invitationCountFor($workspace));
        $this->assertNotNull($user->onboardingProfile->fresh()->workspace_setup_completed_at);
    }

    public function test_duplicate_emails_in_one_submit_create_a_single_invitation(): void
    {
        [$user, $workspace] = $this->userWithWorkspace();

        $this->actingAs($user)->post(route('onboarding.invite.store'), [
            'invites' => [
                ['email' => 'dupe@company.com', 'role' => 'member'],
                ['email' => 'DUPE@company.com', 'role' => 'viewer'],
            ],
        ])->assertRedirect(route('welcome'));

        $this->assertSame(1, $this->invitationCountFor($workspace));
    }

    public function test_invalid_email_is_skipped_but_valid_rows_still_invited(): void
    {
        [$user, $workspace] = $this->userWithWorkspace();

        $this->actingAs($user)->post(route('onboarding.invite.store'), [
            'invites' => [
                ['email' => 'not-an-email', 'role' => 'member'],
                ['email' => 'good@company.com', 'role' => 'member'],
            ],
        ])->assertRedirect(route('welcome'));

        $this->assertSame(1, $this->invitationCountFor($workspace));
        $this->assertDatabaseHas('workspace_invitations', ['email' => 'good@company.com']);
        $this->assertDatabaseMissing('workspace_invitations', ['email' => 'not-an-email']);
    }

    public function test_existing_member_is_not_invited(): void
    {
        [$user, $workspace] = $this->userWithWorkspace();

        // Another active member of this workspace.
        $member = User::factory()->create(['email' => 'member@company.com']);
        WorkspaceMembership::create([
            'workspace_id' => $workspace->id, 'user_id' => $member->id,
            'role' => 'member', 'status' => 'active', 'joined_at' => now(),
        ]);

        $this->actingAs($user)->post(route('onboarding.invite.store'), [
            'invites' => [['email' => 'member@company.com', 'role' => 'admin']],
        ])->assertRedirect(route('welcome'));

        $this->assertSame(0, $this->invitationCountFor($workspace));
    }

    public function test_owner_is_not_an_invitable_role(): void
    {
        [$user] = $this->userWithWorkspace();

        $this->actingAs($user)->post(route('onboarding.invite.store'), [
            'invites' => [['email' => 'x@company.com', 'role' => 'owner']],
        ])->assertSessionHasErrors('invites.0.role');
    }
}
