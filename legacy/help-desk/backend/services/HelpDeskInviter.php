<?php

namespace App\Services\HelpDesk;

use App\Models\HelpDesk;
use App\Models\HelpDeskInvite;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Services\WorkspaceInviter;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Invite a coworker who is not in the workspace yet, straight into the Help Desk
 * (docs/features/help-desk.md, FR-1.5; §4's "Invite Coworker").
 *
 * There is deliberately NO second invitation pipeline (decision H11). The workspace already has
 * one — a hashed token, an email, an expiry, an acceptance screen, seat checks, and a Members
 * screen that lists what is outstanding — and a Help Desk copy of all of that would be a second
 * set of rules to keep in step. This sends the workspace invitation and records what should
 * happen in the Help Desk when it is accepted, which is precisely the third acceptance
 * criterion: "workspace membership PLUS Help Desk membership after acceptance".
 */
class HelpDeskInviter
{
    /**
     * The workspace role an invited Help Desk coworker joins with (decision H12).
     *
     * `member` — the ordinary role. Not `admin`, because inviting somebody to answer support
     * email is not a reason to hand them the workspace; not `guest`, because a guest is a
     * restricted outsider and this person works here. Their Help Desk role, which is the one
     * that decides what they can do in the Help Desk, is chosen separately.
     */
    public const WORKSPACE_ROLE = 'member';

    public function __construct(
        private readonly WorkspaceInviter $invitations,
        private readonly HelpDeskActivityRecorder $activity,
    ) {}

    /**
     * Invite one coworker.
     *
     * @param  array<int, int>  $inboxIds
     * @return array{status: string, email: string, invite?: HelpDeskInvite}
     */
    public function invite(HelpDesk $helpDesk, User $actor, string $email, string $role, array $inboxIds = []): array
    {
        if (! array_key_exists($role, (array) config('help-desk.roles', []))) {
            throw ValidationException::withMessages(['role' => 'That is not a Help Desk role.']);
        }

        $email = strtolower(trim($email));
        $workspace = $helpDesk->tenant;

        /*
         * The workspace invitation does the work, and its per-row result is passed straight
         * back: `already_member`, `no_seats`, `already_invited` and the rest are answers this
         * screen has to show, and rewording them here would mean two vocabularies for one
         * outcome. `already_member` in particular is not an error — it is the screen telling
         * you to use Add member instead.
         */
        $result = $this->invitations->invite($workspace, $actor, [
            ['email' => $email, 'role' => self::WORKSPACE_ROLE],
        ])[0] ?? ['status' => 'invalid_email', 'email' => $email];

        if (($result['status'] ?? null) !== 'invited') {
            return ['status' => (string) $result['status'], 'email' => $email];
        }

        $invitation = WorkspaceInvitation::query()
            ->where('email', $email)
            ->where('status', WorkspaceInvitation::STATUS_PENDING)
            ->latest('id')
            ->firstOrFail();

        $invite = DB::transaction(fn () => HelpDeskInvite::updateOrCreate(
            ['workspace_invitation_id' => $invitation->id],
            [
                'tenant_id' => $helpDesk->tenant_id,
                'help_desk_id' => $helpDesk->id,
                'email' => $email,
                'role' => $role,
                'inbox_ids' => array_values(array_map('intval', $inboxIds)),
                'invited_by' => $actor->id,
            ],
        ));

        $this->activity->invited($helpDesk, $actor, $email, $role);

        return ['status' => 'invited', 'email' => $email, 'invite' => $invite];
    }

    /**
     * Withdraw an outstanding invitation.
     *
     * Revokes the WORKSPACE invitation, which is the thing the link resolves to — deleting only
     * the Help Desk row would leave a live link that still admits somebody to the workspace,
     * which is not what "revoke" means on this screen.
     */
    public function revoke(HelpDesk $helpDesk, User $actor, HelpDeskInvite $invite): void
    {
        $invitation = $invite->invitation;

        if ($invitation && $invitation->isPending()) {
            $invitation->forceFill(['status' => WorkspaceInvitation::STATUS_REVOKED])->save();
        }

        $this->activity->inviteRevoked($helpDesk, $actor, (string) $invite->email);

        $invite->delete();
    }
}
