<?php

namespace Tests\Feature\Workspace;

use App\Mail\LoginCodeMail;
use App\Mail\WorkspaceInvitationMail;
use App\Models\EmailVerificationCode;
use App\Models\OnboardingProfile;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMembership;
use App\Services\OnboardingRouter;
use App\Services\WorkspaceCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Member invite flow — acceptance & membership activation.
 * See docs/features/member-invite-flow.md; § refs are to the ProjectBlock invitation spec.
 */
class InvitationAcceptTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Workspace} owner + their workspace */
    private function owner(string $slug = 'acme-inc'): array
    {
        $user = User::factory()->create(['full_name' => 'Robert Smith', 'email' => "owner-{$slug}@example.com"]);
        $workspace = app(WorkspaceCreator::class)->create($user, [
            'name' => 'Help Desk Implementation', 'slug' => $slug, 'company_size' => '2-10',
        ]);

        return [$user->fresh(), $workspace];
    }

    /**
     * Send a real invitation and return the raw token from the emailed link — the only place
     * the raw token ever exists (§13).
     */
    private function inviteAndCaptureToken(User $owner, string $email = 'john@example.com', string $role = 'manager'): string
    {
        Mail::fake();

        $this->actingAs($owner)->post(route('workspaces.invite.store'), [
            'invites' => [['email' => $email, 'role' => $role]],
        ])->assertRedirect(route('welcome'));

        $token = null;
        Mail::assertSent(WorkspaceInvitationMail::class, function (WorkspaceInvitationMail $mail) use (&$token, $email) {
            if ($mail->invitedEmail !== $email) {
                return false;
            }
            $token = str($mail->acceptUrl)->afterLast('/invite/')->toString();

            return true;
        });

        $this->assertNotNull($token, 'No invitation email was queued.');

        // The invitation link is opened by the invited person, not by the administrator who
        // just sent it — drop the acting-as owner so the rest of the test starts as a guest.
        Auth::logout();

        return $token;
    }

    /**
     * Complete the 6-digit code step for an email, as the invited user would. The plaintext
     * code is never stored (only its hash), so it is read back off the sent mailable.
     */
    private function verifyCode(string $email): void
    {
        $code = null;
        Mail::assertSent(LoginCodeMail::class, function (LoginCodeMail $mail) use (&$code, $email) {
            if (! $mail->hasTo($email)) {
                return false;
            }
            $code = $mail->code;

            return true;
        });

        $this->assertNotNull(EmailVerificationCode::where('email', $email)->latest('id')->first());
        $this->post(route('auth.verify'), ['email' => $email, 'code' => $code]);
    }

    public function test_inviting_sends_an_email_whose_link_carries_a_token_that_is_only_stored_hashed(): void
    {
        [$owner, $workspace] = $this->owner();

        $token = $this->inviteAndCaptureToken($owner);

        $invitation = $workspace->run(fn () => WorkspaceInvitation::first());
        $this->assertNotSame($token, $invitation->token, 'The raw token must never be stored.');
        $this->assertSame(hash('sha256', $token), $invitation->token);
        $this->assertSame('pending', $invitation->status);
    }

    public function test_the_landing_page_shows_the_workspace_inviter_role_and_email(): void
    {
        [$owner, $workspace] = $this->owner();
        $token = $this->inviteAndCaptureToken($owner);

        $this->get(route('invitations.show', ['token' => $token]))
            ->assertOk()
            ->assertSee($workspace->name)
            ->assertSee('Robert Smith')
            ->assertSee('Manager')
            ->assertSee('john@example.com');
    }

    public function test_expired_revoked_and_unknown_links_each_report_their_own_state(): void
    {
        [$owner, $workspace] = $this->owner();

        // Unknown token — same panel as a cancelled one, so it cannot be used to probe (§73).
        $this->get(route('invitations.show', ['token' => str_repeat('a', 64)]))
            ->assertOk()->assertSee('Invitation no longer available');

        $expiredToken = $this->inviteAndCaptureToken($owner, 'expired@example.com');
        $workspace->run(fn () => WorkspaceInvitation::where('email', 'expired@example.com')
            ->first()->forceFill(['expires_at' => now()->subDay()])->save());

        $this->get(route('invitations.show', ['token' => $expiredToken]))
            ->assertOk()->assertSee('Invitation expired');

        // Reading a lapsed invitation retires it, so it stops occupying the Members screen.
        $this->assertSame('expired', $workspace->run(
            fn () => WorkspaceInvitation::where('email', 'expired@example.com')->first()->status
        ));

        $revokedToken = $this->inviteAndCaptureToken($owner, 'revoked@example.com');
        $workspace->run(fn () => WorkspaceInvitation::where('email', 'revoked@example.com')
            ->first()->forceFill(['status' => 'revoked'])->save());

        $this->get(route('invitations.show', ['token' => $revokedToken]))
            ->assertOk()->assertSee('Invitation no longer available');
    }

    public function test_an_existing_user_signed_in_with_a_matching_email_joins_in_one_step(): void
    {
        [$owner, $workspace] = $this->owner();
        $token = $this->inviteAndCaptureToken($owner, 'existing@example.com', 'member');

        // An existing, fully onboarded account (§55: no onboarding is repeated).
        $invited = User::factory()->create(['email' => 'existing@example.com', 'full_name' => 'John Smith']);
        OnboardingProfile::create(['user_id' => $invited->id, 'current_step' => OnboardingProfile::STEP_COMPLETED]);

        $this->actingAs($invited)->get(route('invitations.show', ['token' => $token]))
            ->assertOk()->assertSee('Accept invitation');

        $this->actingAs($invited)->post(route('invitations.accept', ['token' => $token]))
            ->assertRedirect(route('invitations.joined'));

        $membership = WorkspaceMembership::where('workspace_id', $workspace->id)
            ->where('user_id', $invited->id)->first();
        $this->assertNotNull($membership);
        $this->assertSame('member', $membership->role);   // role comes from the invitation (§40)
        $this->assertSame('active', $membership->status);
        $this->assertNotNull($membership->joined_at);

        // The invited workspace becomes the active one (§51/§84).
        $this->assertSame($workspace->id, $invited->fresh()->current_workspace_id);

        $invitation = $workspace->run(fn () => WorkspaceInvitation::where('email', 'existing@example.com')->first());
        $this->assertSame('accepted', $invitation->status);
        $this->assertNotNull($invitation->accepted_at);
        $this->assertSame($invited->id, $invitation->user_id);
    }

    public function test_accepting_twice_creates_exactly_one_membership(): void
    {
        [$owner, $workspace] = $this->owner();
        $token = $this->inviteAndCaptureToken($owner, 'twice@example.com');
        $invited = User::factory()->create(['email' => 'twice@example.com']);

        $this->actingAs($invited)->post(route('invitations.accept', ['token' => $token]))
            ->assertRedirect(route('invitations.joined'));

        // A refresh / double submit re-runs acceptance against an already-accepted invitation.
        $this->actingAs($invited)->post(route('invitations.accept', ['token' => $token]))
            ->assertRedirect(route('invitations.joined'));

        $this->assertSame(1, WorkspaceMembership::where('workspace_id', $workspace->id)
            ->where('user_id', $invited->id)->count());
    }

    public function test_an_accepted_link_reopened_by_the_member_sends_them_into_the_workspace(): void
    {
        [$owner] = $this->owner();
        $token = $this->inviteAndCaptureToken($owner, 'reopen@example.com');
        $invited = User::factory()->create(['email' => 'reopen@example.com']);

        $this->actingAs($invited)->post(route('invitations.accept', ['token' => $token]));

        // §64: still a member — go to the workspace rather than a dead end.
        $this->actingAs($invited)->get(route('invitations.show', ['token' => $token]))
            ->assertRedirect(route('welcome'));

        // A signed-out visitor just sees that it was used.
        Auth::logout();
        $this->get(route('invitations.show', ['token' => $token]))
            ->assertOk()->assertSee('already been accepted');
    }

    public function test_a_signed_in_user_with_a_different_email_cannot_accept(): void
    {
        [$owner, $workspace] = $this->owner();
        $token = $this->inviteAndCaptureToken($owner, 'john@example.com');

        $someoneElse = User::factory()->create(['email' => 'someone.else@example.com']);

        $this->actingAs($someoneElse)->get(route('invitations.show', ['token' => $token]))
            ->assertOk()
            ->assertSee('john@example.com')
            ->assertSee('signed in as a different account');

        // §80: the page is not authoritative — posting directly is refused too.
        $this->actingAs($someoneElse)->post(route('invitations.accept', ['token' => $token]))
            ->assertRedirect(route('invitations.show', ['token' => $token]));

        $this->assertSame(0, WorkspaceMembership::where('workspace_id', $workspace->id)
            ->where('user_id', $someoneElse->id)->count());
    }

    public function test_a_new_user_signs_up_from_the_invitation_and_never_sees_create_workspace(): void
    {
        [$owner, $workspace] = $this->owner();
        $token = $this->inviteAndCaptureToken($owner, 'newbie@example.com', 'guest');

        // Accept as a guest: a code goes to the invited address, which is locked (§22).
        $this->post(route('invitations.start', ['token' => $token]))
            ->assertRedirect(route('auth.verify.show'));
        $this->assertSame('newbie@example.com', session('pending_email'));

        $this->verifyCode('newbie@example.com');

        $user = User::where('email', 'newbie@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);

        // The invitation is associated with the user before onboarding finishes (§32).
        $this->assertSame($user->id, $workspace->run(
            fn () => WorkspaceInvitation::where('email', 'newbie@example.com')->first()->user_id
        ));

        // Finish the existing onboarding steps, then the router must offer the join step —
        // never first-workspace creation (§29).
        $user->onboardingProfile->forceFill(['current_step' => OnboardingProfile::STEP_COMPLETED])->save();

        $this->actingAs($user->fresh())->get(route('welcome'))
            ->assertRedirect(route('onboarding.workspace'));  // sanity: welcome still guards

        $this->actingAs($user->fresh())->get(route('invitations.pending'))
            ->assertOk()
            ->assertSee($workspace->name)
            ->assertSee('Join');

        $this->actingAs($user->fresh())->post(route('invitations.join'))
            ->assertRedirect(route('invitations.joined'));

        $membership = WorkspaceMembership::where('workspace_id', $workspace->id)
            ->where('user_id', $user->id)->first();
        $this->assertNotNull($membership);
        $this->assertSame('guest', $membership->role);
        $this->assertSame('active', $membership->status);

        // Joining closes out first-workspace setup, so they land in the workspace rather than
        // in "invite your teammates" (§51).
        $this->assertNotNull($user->fresh()->onboardingProfile->workspace_setup_completed_at);
        $this->assertSame('welcome', app(OnboardingRouter::class)->postOnboardingRoute($user->fresh()));
    }

    public function test_onboarding_routes_an_invited_user_to_the_join_step_instead_of_workspace_creation(): void
    {
        [$owner] = $this->owner();
        $this->inviteAndCaptureToken($owner, 'routed@example.com');

        $invited = User::factory()->create(['email' => 'routed@example.com']);
        OnboardingProfile::create(['user_id' => $invited->id, 'current_step' => OnboardingProfile::STEP_COMPLETED]);

        $router = app(OnboardingRouter::class);
        $this->assertSame('invitations.pending', $router->postOnboardingRoute($invited->fresh()));
    }

    public function test_a_user_who_already_belongs_to_a_workspace_is_not_hijacked_by_a_pending_invitation(): void
    {
        [$owner] = $this->owner();
        [$other, $otherWorkspace] = $this->owner('blue-sky');

        // `other` already owns a workspace and is now invited to another one (§83).
        $this->inviteAndCaptureToken($owner, $other->email);

        // D-I5: without following the link this session, their normal destination stands —
        // the invitation waits for them to open it rather than intercepting them.
        $this->assertNotSame('invitations.pending', app(OnboardingRouter::class)
            ->postOnboardingRoute($other->fresh()));
        $this->assertSame($otherWorkspace->id, $other->fresh()->current_workspace_id);
    }

    public function test_a_full_workspace_blocks_the_invitation_and_then_the_join(): void
    {
        [$owner, $workspace] = $this->owner();
        $token = $this->inviteAndCaptureToken($owner, 'seat@example.com');

        // One owner + one pending invitation = 2 seats used. Cap the workspace there, as a
        // downgrade between sending and accepting would (§34/§67).
        config(['workspace.seat_limit' => 2]);

        // No room for another invitation (§10).
        Mail::fake();
        $this->actingAs($owner)->post(route('workspaces.invite.store'), [
            'invites' => [['email' => 'toolate@example.com', 'role' => 'member']],
        ]);
        $this->assertSame(0, $workspace->run(fn () => WorkspaceInvitation::where('email', 'toolate@example.com')->count()));

        // And the outstanding invitation cannot be activated either: the seat it reserved is
        // released as it is accepted, but the workspace is already at its limit.
        config(['workspace.seat_limit' => 1]);
        $invited = User::factory()->create(['email' => 'seat@example.com']);

        $this->actingAs($invited)->post(route('invitations.accept', ['token' => $token]))
            ->assertRedirect(route('invitations.show', ['token' => $token]));

        $this->assertSame(0, WorkspaceMembership::where('workspace_id', $workspace->id)
            ->where('user_id', $invited->id)->count());

        $this->actingAs($invited)->get(route('invitations.show', ['token' => $token]))
            ->assertOk()->assertSee('does not have an available member seat');
    }

    public function test_role_changed_while_pending_is_the_role_that_is_applied(): void
    {
        [$owner, $workspace] = $this->owner();
        $token = $this->inviteAndCaptureToken($owner, 'promoted@example.com', 'guest');

        // §41: an admin edits the invitation before it is accepted.
        $workspace->run(fn () => WorkspaceInvitation::where('email', 'promoted@example.com')
            ->first()->forceFill(['role' => 'manager'])->save());

        $invited = User::factory()->create(['email' => 'promoted@example.com']);
        $this->actingAs($invited)->post(route('invitations.accept', ['token' => $token]));

        $this->assertSame('manager', WorkspaceMembership::where('workspace_id', $workspace->id)
            ->where('user_id', $invited->id)->first()->role);
    }

    public function test_an_expired_invitation_can_never_be_activated(): void
    {
        [$owner, $workspace] = $this->owner();
        $token = $this->inviteAndCaptureToken($owner, 'lapsed@example.com');
        $workspace->run(fn () => WorkspaceInvitation::where('email', 'lapsed@example.com')
            ->first()->forceFill(['expires_at' => now()->subMinute()])->save());

        $invited = User::factory()->create(['email' => 'lapsed@example.com']);

        $this->actingAs($invited)->post(route('invitations.accept', ['token' => $token]))
            ->assertRedirect(route('invitations.show', ['token' => $token]));

        $this->assertSame(0, WorkspaceMembership::where('workspace_id', $workspace->id)
            ->where('user_id', $invited->id)->count());
    }

    public function test_the_invited_member_appears_as_active_on_the_members_screen(): void
    {
        [$owner, $workspace] = $this->owner();
        $token = $this->inviteAndCaptureToken($owner, 'listed@example.com', 'member');
        $invited = User::factory()->create(['email' => 'listed@example.com', 'full_name' => 'John Smith']);

        $this->actingAs($invited)->post(route('invitations.accept', ['token' => $token]));

        // §57: the pending row becomes an active member for the administrator.
        $this->actingAs($owner)->get(route('settings.members'))
            ->assertOk()
            ->assertViewHas('bootstrap', fn ($b) => collect($b['people'])->contains(
                fn ($p) => $p['email'] === 'listed@example.com' && $p['role'] === 'member'
            ) && collect($b['pending'])->doesntContain(fn ($i) => $i['email'] === 'listed@example.com'));
    }
}
