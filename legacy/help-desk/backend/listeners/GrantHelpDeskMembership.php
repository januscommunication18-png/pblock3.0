<?php

namespace App\Listeners;

use App\Events\WorkspaceInvitationAccepted;
use App\Models\HelpDeskInvite;
use App\Services\HelpDesk\HelpDeskActivityRecorder;
use App\Services\HelpDesk\HelpDeskMemberManager;
use Illuminate\Support\Facades\Log;

/**
 * Turns an accepted workspace invitation into Help Desk membership (FR-1.5).
 *
 * This is the second half of decision H11: one invitation, one link, one acceptance — and when
 * it lands, whoever recorded an intention against it gets to act. The third acceptance
 * criterion is exactly this listener's job: "a new coworker invitation results in Workspace
 * membership plus Help Desk membership after acceptance".
 *
 * Runs synchronously rather than on the queue. Somebody accepting an invitation is taken
 * straight into the workspace, and a Help Desk that appears a few seconds later — or not at
 * all, if the worker is down — is the kind of "did it work?" that makes people click again.
 */
class GrantHelpDeskMembership
{
    public function __construct(
        private readonly HelpDeskMemberManager $members,
        private readonly HelpDeskActivityRecorder $activity,
    ) {}

    public function handle(WorkspaceInvitationAccepted $event): void
    {
        /*
         * Read WITHOUT the tenant scope: acceptance happens outside any workspace context —
         * the person is not in the workspace until this moment — so the scope would match
         * nothing and every invited coworker would arrive with no Help Desk access. The
         * explicit tenant filter is the isolation (CLAUDE.md §7).
         */
        $invite = HelpDeskInvite::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $event->workspace->id)
            ->where('workspace_invitation_id', $event->invitation->id)
            ->whereNull('redeemed_at')
            ->first();

        if (! $invite) {
            return;
        }

        $helpDesk = $invite->helpDesk()->withoutGlobalScopes()->first();

        if (! $helpDesk) {
            return;
        }

        try {
            $event->workspace->run(function () use ($invite, $helpDesk, $event) {
                $this->members->add(
                    $helpDesk,
                    $event->user,
                    $event->user->id,
                    (string) $invite->role,
                    (array) ($invite->inbox_ids ?? []),
                );

                $this->activity->inviteAccepted($helpDesk, $event->user, (string) $invite->role);
            });

            $invite->forceFill(['redeemed_at' => now()])->save();
        } catch (\Throwable $e) {
            /*
             * Never fail the acceptance itself. They are a workspace member either way, and an
             * exception here would leave them staring at an error page having already joined —
             * the recoverable half is the Help Desk membership, which an administrator can add
             * from the members screen.
             */
            Log::error('help_desk.invite.redeem_failed', [
                'invite_id' => $invite->id,
                'tenant_id' => $invite->tenant_id,
                'user_id' => $event->user->id,
                'reason' => $e->getMessage(),
            ]);
        }
    }
}
