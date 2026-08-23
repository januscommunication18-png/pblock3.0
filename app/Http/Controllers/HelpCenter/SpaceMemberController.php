<?php

namespace App\Http\Controllers\HelpCenter;

use App\Http\Controllers\Controller;
use App\Http\Controllers\HelpCenter\Concerns\GuardsHelpCenter;
use App\Models\HelpCenterSpace;
use App\Models\HelpCenterSpaceMember;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMembership;
use App\Notifications\AddedToHelpCenterSpace;
use App\Services\WorkspaceInviter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Managing who is on a Space (docs/features/help-center.md, P2 §8, P10).
 *
 * Deliberately mirrors Project Settings › Members: same grid, same toolbar, same Add Member
 * dialog, same row actions. A membership means something different here — it says you work this
 * Space's Inbox rather than that you participate in a project — but managing one should not feel
 * like a different application.
 *
 * Every control the UI hides is refused here as well. Hiding an action is not enforcing it.
 */
class SpaceMemberController extends Controller
{
    use GuardsHelpCenter;

    public function __construct(private readonly WorkspaceInviter $inviter) {}

    /**
     * POST /help-center/spaces/{space}/members — Add Member.
     *
     * ONE door for two cases, because from the person clicking "Add Member" they are the same
     * act: they want somebody covering this Inbox, and whether that person already has a
     * ProjectBlock account is a detail they should not have to know before they start typing.
     *
     *   - Already an active coworker → added to the Space immediately.
     *   - Not in the workspace yet   → a workspace invitation goes out, and the Space membership
     *                                  is created pending, linked to that invitation.
     *
     * Invitations go through `WorkspaceInviter`, the same service Settings → Members and the
     * onboarding wizard use. A second way to invite somebody would be a second set of seat
     * checks, expiry rules and emails to keep in step (P2 §8).
     */
    public function store(Request $request, HelpCenterSpace $space): JsonResponse
    {
        $workspace = $this->helpCenterWorkspace();
        abort_unless(Auth::user()->can('update', $space), 403);

        $actor = Auth::user();

        $data = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'role' => ['required', 'string', 'in:'.implode(',', (array) config('workspace.invite_roles'))],
            'department_groups' => ['sometimes', 'array'],
            'department_groups.*' => ['string', 'max:60'],
        ]);

        $email = mb_strtolower(trim($data['email']));

        /*
         * The role has to be one the ACTOR may hand out.
         *
         * `WorkspacePolicy::assignRole` already says only an owner may create another owner and
         * everyone else may assign strictly below themselves. This screen is a new door into
         * inviting people, and a new door into an existing permission must not be a way around
         * it — otherwise an admin who cannot promote somebody to admin on Settings → Members
         * could do it here (HC-D19).
         */
        if (! $actor->can('assignRole', [$workspace, $data['role']])) {
            return response()->json([
                'ok' => false,
                'message' => 'You cannot assign that role.',
            ], 422);
        }

        // Only groups this Space actually defines — a free-text group would be a label nothing
        // on the Overview points at.
        $groups = array_values(array_intersect(
            array_map('trim', (array) ($data['department_groups'] ?? [])),
            $space->groupList(),
        ));

        if ($space->members()->where('email', $email)->exists()) {
            return response()->json([
                'ok' => false,
                'message' => 'That person is already on this Space.',
            ], 422);
        }

        $user = User::query()
            ->whereIn('id', WorkspaceMembership::query()
                ->where('workspace_id', $workspace->id)
                ->where('status', WorkspaceMembership::STATUS_ACTIVE)
                ->where('role', '!=', 'guest')
                ->pluck('user_id'))
            ->where('email', $email)
            ->first();

        $member = $space->members()->create([
            'tenant_id' => $space->tenant_id,
            'user_id' => $user?->id,
            'email' => $email,
            // Only meaningful for somebody being invited: an existing coworker already has a
            // workspace role, and adding them to a Space must not silently change it.
            'invited_role' => $user === null ? $data['role'] : null,
            'department_groups' => $groups,
            'created_by' => $actor->id,
        ]);

        if ($user !== null) {
            /*
             * An existing coworker gets told, because nothing else will tell them.
             *
             * There is no invitation to accept — they are simply on the Space now — so without
             * this the first they would know is a new entry appearing in their navigation. That
             * is not how somebody should learn that customer email is partly theirs to answer.
             *
             * Caught rather than allowed to bubble: on a `sync` queue this notification sends
             * inside the request, and a mail server that is down would then fail the Add Member
             * call itself — leaving the admin looking at an error for a membership that was in
             * fact created. The membership is the thing being asked for; the heads-up is not.
             */
            try {
                $user->notify(new AddedToHelpCenterSpace($space, $actor->displayName(), $groups));
            } catch (\Throwable $e) {
                Log::error('help-center.space_member.notify_failed', [
                    'space_id' => $space->id,
                    'user_id' => $user->id,
                    'reason' => $e->getMessage(),
                ]);
            }

            return response()->json([
                'ok' => true,
                'member' => $this->payload($member->fresh()->load('user')),
                'message' => 'Member added.',
            ]);
        }

        return $this->inviteToWorkspace($workspace, $actor, $space, $member, $email, $data['role']);
    }

    /**
     * The half of Add Member that covers somebody who is not in the workspace yet.
     *
     * Both remaining cases land here and both are the same act: an address that has no active
     * workspace membership gets a workspace invitation carrying this Space's context. Whether
     * that address already has a ProjectBlock account changes nothing here — acceptance signs
     * an existing account in and creates a new one for a stranger, which is `InvitationController`'s
     * job, not this screen's (P10).
     *
     * What this method is careful about is the invitation NOT going out. `WorkspaceInviter`
     * reports per-recipient statuses and several of them mean "no email was sent" — and the
     * pending member row created a moment ago then says somebody was invited who was not. Each
     * of those either recovers (resend) or takes the row back out and says why.
     */
    private function inviteToWorkspace(
        Workspace $workspace,
        User $actor,
        HelpCenterSpace $space,
        HelpCenterSpaceMember $member,
        string $email,
        string $role,
    ): JsonResponse {
        $context = $this->inviteContext($space, $actor);

        $status = $this->inviter->invite($workspace, $actor, [['email' => $email, 'role' => $role]], $context)[0]['status'] ?? 'invited';

        /*
         * Already had an invitation outstanding — reissue it rather than refuse.
         *
         * The admin asked for this person to be on this Space, and the email already sitting in
         * that mailbox says nothing about a Space. `resend()` mints a fresh token, which also
         * retires the older link (P10).
         */
        if ($status === 'already_invited') {
            $existing = WorkspaceInvitation::query()
                ->where('email', $email)
                ->where('status', WorkspaceInvitation::STATUS_PENDING)
                ->latest('id')
                ->first();

            if ($existing !== null && $this->inviter->resend($workspace, $actor, $existing, $context)) {
                $status = 'invited';
            }
        }

        if ($status !== 'invited') {
            // Nothing was sent, so nothing is pending. Leaving the row would put a permanent
            // "Invited" in the grid for somebody who was never written to.
            $member->delete();

            return response()->json([
                'ok' => false,
                'message' => $this->inviteFailureMessage($status, $email),
            ], 422);
        }

        /*
         * Link the membership to the invitation that was just created for it.
         *
         * Looked up afterwards rather than returned by the inviter: `invite()` reports
         * per-recipient statuses, not models. This link is what lets the acceptance listener
         * finish the exact membership this invitation was sent for.
         */
        $invitation = WorkspaceInvitation::query()
            ->where('email', $email)
            ->where('status', WorkspaceInvitation::STATUS_PENDING)
            ->latest('id')
            ->first();

        if ($invitation !== null) {
            $member->forceFill(['workspace_invitation_id' => $invitation->id])->save();
        }

        return response()->json([
            'ok' => true,
            'member' => $this->payload($member->fresh()->load('user')),
            'message' => 'Invitation sent to '.$email.'.',
        ]);
    }

    /**
     * The Space wording the invitation email carries (P10).
     *
     * Plain strings rather than a model: the mailable is queued and serialized, and a
     * tenant-scoped Space rebuilt by a worker with no tenancy context comes back empty.
     *
     * @return array{title:string, line:string, label:string, value:string}
     */
    private function inviteContext(HelpCenterSpace $space, User $actor): array
    {
        return [
            'title' => 'You have been invited to a Help Center Space',
            'line' => $actor->displayName().' has invited you to join the "'.$space->name
                .'" Help Center Space. Accept the invitation to get access — you will land straight in the Space.',
            'label' => 'Help Center Space',
            'value' => (string) $space->name,
        ];
    }

    /** Why no invitation went out, in the words the admin who clicked Add Member needs. */
    private function inviteFailureMessage(string $status, string $email): string
    {
        return match ($status) {
            'no_seats' => 'This workspace has no seats left. Free a seat or upgrade the plan, then invite '.$email.' again.',
            'suspended_member' => $email.' is a suspended member of this workspace. Reactivate them on Settings → Members first.',
            /*
             * Reachable only for a GUEST. Anyone with a full active membership was found by the
             * lookup above and never got here, so this is the one workspace role the Space
             * cannot take: a guest is somebody with narrow access to specific work, and working
             * an Inbox is not that. Raising their workspace role is the admin's decision to
             * make on Settings → Members, not a side effect of adding them to a Space.
             */
            'already_member' => $email.' is a guest in this workspace, so they cannot work a Space Inbox. Change their workspace role on Settings → Members first.',
            'invalid_role' => 'You cannot assign that role.',
            default => 'We could not send an invitation to '.$email.'. Check the address and try again.',
        };
    }

    /** PATCH /help-center/spaces/{space}/members/{member} — change their Department Groups. */
    public function update(Request $request, HelpCenterSpace $space, int $member): JsonResponse
    {
        $this->helpCenterWorkspace();
        abort_unless(Auth::user()->can('update', $space), 403);

        // Found through the Space, so a member of another Space cannot be reached by id.
        $model = $space->members()->findOrFail($member);

        $data = $request->validate([
            'department_groups' => ['present', 'array'],
            'department_groups.*' => ['string', 'max:60'],
        ]);

        $model->forceFill(['department_groups' => array_values($data['department_groups'])])->save();

        return response()->json([
            'ok' => true,
            'member' => $this->payload($model->fresh()->load('user')),
            'message' => 'Department groups updated.',
        ]);
    }

    /** DELETE /help-center/spaces/{space}/members/{member} */
    public function destroy(HelpCenterSpace $space, int $member): JsonResponse
    {
        $this->helpCenterWorkspace();
        abort_unless(Auth::user()->can('update', $space), 403);

        $model = $space->members()->findOrFail($member);

        /*
         * The Space Lead cannot be removed from their own Space.
         *
         * Removing them would leave a Space led by somebody with no access to it — a state with
         * no way back through this screen, because the lead picker only offers members.
         */
        if ($model->user_id !== null && (int) $model->user_id === (int) $space->lead_user_id) {
            return response()->json([
                'ok' => false,
                'message' => 'This person leads the Space. Change the Space Lead before removing them.',
            ], 422);
        }

        $model->delete();

        return response()->json(['ok' => true, 'message' => 'Member removed.']);
    }

    /**
     * One row of the members grid.
     *
     * @return array<string, mixed>
     */
    private function payload(HelpCenterSpaceMember $member): array
    {
        $user = $member->user;
        $name = $user?->displayName() ?: $member->email;

        return $member->toPayload() + [
            'name' => $name,
            'initial' => mb_strtoupper(mb_substr((string) $name, 0, 1)) ?: '?',
            'email' => $user?->email ?? $member->email,
            'avatar_url' => $user?->avatar_url,
            // What they are in the WORKSPACE. A Space has no roles of its own — access to a
            // Space is binary, and inventing a second role vocabulary here would mean two
            // answers to "what may this person do".
            'workspace_role' => $member->user_id === null
                ? $member->invited_role
                : WorkspaceMembership::query()
                    ->where('workspace_id', $member->tenant_id)
                    ->where('user_id', $member->user_id)
                    ->value('role'),
            'is_lead' => $member->user_id !== null
                && (int) $member->user_id === (int) $member->space?->lead_user_id,
            'added_at' => $member->created_at?->format('M j, Y'),
            'status' => $member->isPending() ? 'invited' : 'active',
        ];
    }
}
