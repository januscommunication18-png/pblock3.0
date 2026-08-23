<?php

namespace App\Listeners;

use App\Events\WorkspaceInvitationAccepted;
use App\Models\HelpCenterSpaceMember;
use Illuminate\Support\Facades\Log;

/**
 * Finish a Space membership when its invitation is accepted
 * (docs/features/help-center.md, P10).
 *
 * Adding somebody to a Space who has no account yet leaves a `help_center_space_members` row
 * with `user_id` null — a placeholder that says "this address is expected here". Accepting the
 * workspace invitation is the moment that address becomes a person, and without this the row
 * stayed a placeholder forever: the Members grid showed them Invited after they had signed up,
 * they could not be assigned a Request, and nothing in either screen explained why.
 *
 * Matched by INVITATION first and by address second. The invitation link is exact; the address
 * fallback catches rows created before this listener existed and rows whose invitation could
 * not be linked at send time — `WorkspaceInviter` reports per-recipient statuses, and an address
 * it refused (no seats, already invited) has no invitation to point at.
 */
class LinkHelpCenterSpaceMemberships
{
    /**
     * Where the just-joined Space id is left for the post-acceptance screen (P10).
     *
     * Public because it is a contract between this listener and PendingInvitationController —
     * two files that deliberately do not otherwise know about each other.
     */
    public const SESSION_SPACE_KEY = 'help_center.joined_space_id';

    public function handle(WorkspaceInvitationAccepted $event): void
    {
        $email = mb_strtolower((string) $event->invitation->email);

        /*
         * Inside the workspace's own tenancy context.
         *
         * `HelpCenterSpaceMember` is tenant-scoped, and acceptance runs from a public
         * invitation link where no tenancy is established — without this the query would find
         * nothing and fail silently, which is the failure this listener exists to fix.
         */
        $event->workspace->run(function () use ($event, $email) {
            $rows = HelpCenterSpaceMember::query()
                ->whereNull('user_id')
                ->where(function ($q) use ($event, $email) {
                    $q->where('workspace_invitation_id', $event->invitation->id)
                        ->orWhere('email', $email);
                })
                ->get();

            foreach ($rows as $row) {
                $row->forceFill([
                    'user_id' => $event->user->id,
                    // The role travelled with the workspace invitation and has now been applied
                    // to their membership. Keeping a copy here would be a second answer to
                    // "what is this person", free to drift the first time somebody's role changes.
                    'invited_role' => null,
                ])->save();
            }

            if ($rows->isNotEmpty()) {
                /*
                 * Remember which Space this acceptance was really about, so the "Welcome to
                 * {Workspace}" screen can send them into it (P10).
                 *
                 * The invitation flow itself knows nothing about the Help Center — that is the
                 * whole reason this is a listener — so the destination cannot be decided there.
                 * It is left here, in the session, for whoever renders the next screen to use;
                 * if nothing does, the flow lands on the workspace exactly as before.
                 *
                 * Guarded, because acceptance is not always an HTTP request: the same event
                 * fires from console and queued paths, where there is no session to write to.
                 */
                if (app()->bound('session') && session()->isStarted()) {
                    session()->put(self::SESSION_SPACE_KEY, $rows->first()->help_center_space_id);
                }

                Log::info('help-center.space_member.linked', [
                    'user_id' => $event->user->id,
                    'spaces' => $rows->pluck('help_center_space_id')->all(),
                ]);
            }
        });
    }
}
