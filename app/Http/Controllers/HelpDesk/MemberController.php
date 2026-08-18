<?php

namespace App\Http\Controllers\HelpDesk;

use App\Http\Requests\HelpDesk\StoreMemberRequest;
use App\Http\Requests\HelpDesk\UpdateMemberRequest;
use App\Models\HelpDesk;
use App\Models\HelpDeskMember;
use App\Services\HelpDesk\HelpDeskAccess;
use App\Services\HelpDesk\HelpDeskMemberManager;
use App\Services\HelpDesk\HelpDeskProvisioner;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;

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
        private readonly HelpDeskMemberManager $members,
    ) {
        parent::__construct($access, $provisioner);
    }

    /** GET /help-desk/settings/members */
    public function index(): View
    {
        $helpDesk = $this->helpDesk();
        $this->guardAdminister($helpDesk);

        return $this->page('settings.members', [
            'members' => $this->memberRows($helpDesk),
            'inboxes' => $this->inboxRows($helpDesk),
            'assignable' => $this->assignableUsers($helpDesk),
            'roles' => $this->roleOptions(),
            'canAssignAdmin' => $this->user()->can('assignRole', [$helpDesk, HelpDeskMember::ROLE_ADMIN]),
            'endpoints' => [
                'store' => route('help-desk.members.store'),
                'member' => route('help-desk.members.update', ['member' => '__ID__']),
                'inboxes' => route('help-desk.inboxes.store'),
                'inbox' => route('help-desk.inboxes.update', ['inbox' => '__ID__']),
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
            $member = $this->members->updateRole($helpDesk, $member, $role);
        }

        if ($request->has('status')) {
            $member = $this->members->setStatus($member, (string) $request->validated('status'));
        }

        /*
         * Applied AFTER the role, deliberately. Moving somebody up to a role that reaches every
         * inbox clears their named ones, and applying a list first would write rows the role
         * change then throws away.
         */
        if ($request->has('inbox_ids')) {
            $this->members->setInboxes($helpDesk, $member, (array) $request->validated('inbox_ids'));
        }

        return $this->listResponse($helpDesk);
    }

    /** DELETE /help-desk/settings/members/{member} */
    public function destroy(HelpDeskMember $member): JsonResponse
    {
        $helpDesk = $this->helpDesk();
        $this->guardAdminister($helpDesk);
        $this->guardTarget($helpDesk, $member);

        $this->members->remove($member);

        return $this->listResponse($helpDesk);
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
            'assignable' => $this->assignableUsers($helpDesk),
        ], $extra));
    }
}
