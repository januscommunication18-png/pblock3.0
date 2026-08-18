<?php

namespace App\Services\HelpDesk;

use App\Models\HelpDesk;
use App\Models\HelpDeskInbox;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

/**
 * Gets a workspace's Help Desk, creating it the first time somebody opens the app
 * (docs/features/help-desk.md, slice 2).
 *
 * Provisioning is LAZY for the same reason workspace settings are: switching the app on is a
 * flag, and a workspace that turns Help Desk on to look at it should not have rows written for
 * a support operation it never sets up. The first visit by somebody who can administer it is
 * what creates the Help Desk and its first inbox.
 *
 * The first inbox is part of provisioning rather than something to be created later, because
 * inbox-level access (FR-1.7) needs an inbox to grant — a Help Desk with none is a member
 * screen where the access column can only ever be empty.
 */
class HelpDeskProvisioner
{
    /** The workspace's Help Desk, or null if it has never been set up. */
    public function existing(Workspace $workspace): ?HelpDesk
    {
        return HelpDesk::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $workspace->id)
            ->first();
    }

    /**
     * The workspace's Help Desk, created if this is the first time.
     *
     * In a transaction with the first inbox: a Help Desk that exists without one is a state
     * every screen after this would have to carry a branch for.
     */
    public function for(Workspace $workspace, ?User $creator = null): HelpDesk
    {
        $existing = $this->existing($workspace);

        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($workspace, $creator) {
            /*
             * Re-read inside the transaction. Two people opening Help Desk at the same moment
             * on a workspace that has never had one is not exotic — it is what happens when an
             * admin shares the link — and `help_desks.tenant_id` is unique, so the loser of
             * that race would get a database error instead of a Help Desk.
             */
            $helpDesk = $this->existing($workspace);

            if ($helpDesk) {
                return $helpDesk;
            }

            $helpDesk = HelpDesk::create([
                'tenant_id' => $workspace->id,
                'name' => $workspace->name,
                'created_by' => $creator?->id,
            ]);

            HelpDeskInbox::create([
                'tenant_id' => $workspace->id,
                'help_desk_id' => $helpDesk->id,
                'name' => (string) config('help-desk.default_inbox', 'Support'),
                'created_by' => $creator?->id,
            ]);

            return $helpDesk;
        });
    }
}
