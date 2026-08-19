<?php

namespace App\Http\Controllers\HelpDesk;

use App\Http\Requests\HelpDesk\InviteCoworkerRequest;
use App\Http\Requests\HelpDesk\StoreMemberRequest;
use App\Http\Requests\HelpDesk\UpdateMemberRequest;
use App\Models\HelpDesk;
use App\Models\HelpDeskInvite;
use App\Models\HelpDeskMember;
use App\Services\HelpDesk\HelpDeskAccess;
use App\Services\HelpDesk\HelpDeskInviter;
use App\Services\HelpDesk\HelpDeskMemberManager;
use App\Services\HelpDesk\HelpDeskProvisioner;
use App\Services\HelpDesk\HelpDeskSpaceContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Help Desk › Settings › Members (docs/features/help-desk.md, FR-1.4/1.6/1.7/1.8).
 *
 * "Add Existing Workspace Member" (§4) is what this screen does in slice 2; "Invite Coworker"
 * is slice 3 and lands on the same manager service.
 *
 * Every mutation re-authorizes against the resolved Help Desk, and every one of them returns
 * the whole member list — the screen then renders the server's answer instead of its own guess
 * at what just happened, which is what stops two administrators editing at once from leaving a
 * row on screen that no longer exists (§9, concurrent updates).
 */
class MemberController extends AreaController
{
    public function __construct(
        HelpDeskAccess $access,
        HelpDeskProvisioner $provisioner,
        HelpDeskSpaceContext $spaceContext,
        private readonly HelpDeskMemberManager $members,
    ) {
        parent::__construct($access, $provisioner, $spaceContext);
    }

    /** GET /help-desk/settings/members */
    public function index(): View
    {
        $helpDesk = $this->helpDesk();
        $this->guardAdminister($helpDesk);

        return $this->page('settings.members', [
            'members' => $this->memberRows($helpDesk),
            'pending' => $this->pendingInvites($helpDesk),
            'inboxes' => $this->inboxRows($helpDesk),
            'assignable' => $this->assignableUsers($helpDesk),
            'roles' => $this->roleOptions(),
            'canAssignAdmin' => $this->user()->can('assignRole', [$helpDesk, HelpDeskMember::ROLE_ADMIN]),
            'endpoints' => [
                'store' => route('help-desk.members.store'),
                'member' => route('help-desk.members.update', ['member' => '__ID__']),
                'invite' => route('help-desk.invites.store'),
                'inviteItem' => route('help-desk.invites.destroy', ['invite' => '__ID__']),
                // Inboxes are configured on their own screen (Phase 2); this screen grants
                // access to them and points at it rather than managing them a second time.
                'inboxesScreen' => route('help-desk.inboxes'),
            ],
        ], ['helpDesk' => $helpDesk]);
    }

    /** POST /help-desk/settings/members */
    public function store(StoreMemberRequest $request): JsonResponse
    {
        $helpDesk = $this->helpDesk();
        $this->guardAdminister($helpDesk);
        $this->guardRole($helpDesk, $request->validated('role'));

        $member = $this->members->add(
            $helpDesk,
            $this->user(),
            (int) $request->validated('user_id'),
            (string) $request->validated('role'),
            (array) $request->validated('inbox_ids', []),
        );

        return $this->listResponse($helpDesk, ['member_id' => $member->id]);
    }

    /** PATCH /help-desk/settings/members/{member} */
    public function update(UpdateMemberRequest $request, HelpDeskMember $member): JsonResponse
    {
        $helpDesk = $this->helpDesk();
        $this->guardAdminister($helpDesk);
        $this->guardTarget($helpDesk, $member);

        if ($request->has('role')) {
            $role = (string) $request->validated('role');
            $this->guardRole($helpDesk, $role);
            $member = $this->members->updateRole($helpDesk, $member, $role, $this->user());
        }

        if ($request->has('status')) {
            $member = $this->members->setStatus($helpDesk, $member, (string) $request->validated('status'), $this->user());
        }

        /*
         * Applied AFTER the role, deliberately. Moving somebody up to a role that reaches every
         * inbox clears their named ones, and applying a list first would write rows the role
         * change then throws away.
         */
        if ($request->has('inbox_ids')) {
            $this->members->setInboxes($helpDesk, $member, (array) $request->validated('inbox_ids'), $this->user());
        }

        return $this->listResponse($helpDesk);
    }

    /** DELETE /help-desk/settings/members/{member} */
    public function destroy(HelpDeskMember $member): JsonResponse
    {
        $helpDesk = $this->helpDesk();
        $this->guardAdminister($helpDesk);
        $this->guardTarget($helpDesk, $member);

        $this->members->remove($helpDesk, $member, $this->user());

        return $this->listResponse($helpDesk);
    }

    /**
     * POST /help-desk/settings/invites — invite a coworker who is not in the workspace (FR-1.5).
     *
     * Throttled at the route, like the workspace's own invite endpoint: this one sends email to
     * an address the requester chooses.
     */
    public function invite(InviteCoworkerRequest $request, HelpDeskInviter $inviter): JsonResponse
    {
        $helpDesk = $this->helpDesk();
        $this->guardAdminister($helpDesk);
        $this->guardRole($helpDesk, $request->validated('role'));

        $result = $inviter->invite(
            $helpDesk,
            $this->user(),
            (string) $request->validated('email'),
            (string) $request->validated('role'),
            (array) $request->validated('inbox_ids', []),
        );

        /*
         * A refused invitation is a 422 with the reason on the field, not a 200 with a status
         * the screen has to interpret: "already a member" and "no seats left" are things the
         * person typing needs to read next to the box they typed in.
         */
        if ($result['status'] !== 'invited') {
            throw ValidationException::withMessages([
                'email' => $this->inviteMessage($result['status']),
            ]);
        }

        return $this->listResponse($helpDesk, ['invited' => $result['email']]);
    }

    /** DELETE /help-desk/settings/invites/{invite} — withdraw an outstanding invitation. */
    public function revokeInvite(HelpDeskInvite $invite, HelpDeskInviter $inviter): JsonResponse
    {
        $helpDesk = $this->helpDesk();
        $this->guardAdminister($helpDesk);
        abort_unless((int) $invite->help_desk_id === (int) $helpDesk->id, 404);

        $inviter->revoke($helpDesk, $this->user(), $invite);

        return $this->listResponse($helpDesk);
    }

    /** The workspace invite flow's per-row outcomes, said the way this screen needs them. */
    private function inviteMessage(string $status): string
    {
        return match ($status) {
            'already_member' => 'They are already in this workspace — add them as a Help Desk member instead.',
            'suspended_member' => 'Their workspace membership is suspended. Reactivate it in Workspace settings first.',
            'already_invited' => 'They have already been invited and have not accepted yet.',
            'no_seats' => 'This workspace has no seats left.',
            'invalid_role' => 'That is not a role this workspace can invite.',
            default => 'That email address could not be invited.',
        };
    }

    /** The member belongs to THIS Help Desk, and this actor may act on them. */
    private function guardTarget(HelpDesk $helpDesk, HelpDeskMember $member): void
    {
        abort_unless((int) $member->help_desk_id === (int) $helpDesk->id, 404);
        abort_unless($this->user()->can('manageMember', [$helpDesk, $member]), 403);
    }

    /** Only somebody who administers the workspace may hand out Help Desk Admin. */
    private function guardRole(HelpDesk $helpDesk, string $role): void
    {
        abort_unless($this->user()->can('assignRole', [$helpDesk, $role]), 403);
    }

    /** @param array<string, mixed> $extra */
    private function listResponse(HelpDesk $helpDesk, array $extra = []): JsonResponse
    {
        return response()->json(array_merge([
            'ok' => true,
            'members' => $this->memberRows($helpDesk),
            'pending' => $this->pendingInvites($helpDesk),
            'assignable' => $this->assignableUsers($helpDesk),
        ], $extra));
    }
}
