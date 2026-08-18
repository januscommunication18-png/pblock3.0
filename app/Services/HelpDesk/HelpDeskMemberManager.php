<?php

namespace App\Services\HelpDesk;

use App\Models\HelpDesk;
use App\Models\HelpDeskInbox;
use App\Models\HelpDeskMember;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adding, re-roling, deactivating and removing Help Desk members
 * (docs/features/help-desk.md, FR-1.4/1.6/1.7/1.8).
 *
 * Every rule that makes Help Desk membership its own thing lives here rather than in the
 * controller, so the invite-acceptance path in slice 3 lands on the same rules instead of a
 * second, similar set of them.
 */
class HelpDeskMemberManager
{
    /**
     * Add an existing workspace member to the Help Desk (§4, "Add Existing Workspace Member").
     *
     * A workspace membership is REQUIRED, and checked here rather than trusted from the screen:
     * Help Desk membership is independent of workspace membership (FR-1.4) but not detached
     * from it — somebody who cannot reach the workspace cannot work in its Help Desk, which is
     * §5's most-restrictive-wins rule applied at the moment of adding.
     *
     * @param  array<int, int>  $inboxIds
     */
    public function add(HelpDesk $helpDesk, User $actor, int $userId, string $role, array $inboxIds = []): HelpDeskMember
    {
        $this->assertRole($role);
        $this->assertInWorkspace($helpDesk, $userId);

        return DB::transaction(function () use ($helpDesk, $actor, $userId, $role, $inboxIds) {
            /*
             * Somebody removed and added back gets their ORIGINAL row restored, not a new one.
             * Their replies, notes and assignments point at that row (acceptance criterion 5),
             * so a fresh membership would leave the history attached to a member who no longer
             * appears anywhere — and the `help_desk_id + user_id` index would then hold two
             * memberships for one person.
             */
            $member = HelpDeskMember::withTrashed()
                ->where('help_desk_id', $helpDesk->id)
                ->where('user_id', $userId)
                ->first();

            if ($member && ! $member->trashed()) {
                throw ValidationException::withMessages([
                    'user_id' => 'That person is already a Help Desk member.',
                ]);
            }

            if ($member) {
                $member->restore();
                $member->forceFill([
                    'role' => $role,
                    'status' => HelpDeskMember::STATUS_ACTIVE,
                ])->save();
            } else {
                $member = HelpDeskMember::create([
                    'tenant_id' => $helpDesk->tenant_id,
                    'help_desk_id' => $helpDesk->id,
                    'user_id' => $userId,
                    'role' => $role,
                    'status' => HelpDeskMember::STATUS_ACTIVE,
                    'created_by' => $actor->id,
                ]);
            }

            $this->setInboxes($helpDesk, $member, $inboxIds);

            return $member->fresh();
        });
    }

    /** Change a member's Help Desk role (FR-1.6). */
    public function updateRole(HelpDesk $helpDesk, HelpDeskMember $member, string $role): HelpDeskMember
    {
        $this->assertRole($role);

        $member->forceFill(['role' => $role])->save();

        /*
         * Moving somebody UP to a role that reaches every inbox drops their named inboxes: the
         * rows would stop being read (see HelpDeskAccess::inboxIds) and would come back to life
         * the moment they were moved back down, granting access nobody chose today.
         */
        if ($member->reachesAllInboxes()) {
            $member->inboxes()->detach();
        }

        return $member->fresh();
    }

    /**
     * Replace the set of inboxes a member may open (FR-1.7).
     *
     * Inbox ids are re-read from THIS Help Desk before syncing, so a crafted request naming an
     * inbox from another workspace grants nothing — the tenant scope already confines the
     * query, and this makes the mismatch a silent no-op rather than a foreign row.
     *
     * @param  array<int, int>  $inboxIds
     */
    public function setInboxes(HelpDesk $helpDesk, HelpDeskMember $member, array $inboxIds): HelpDeskMember
    {
        // Roles that reach everything have nothing to name (config `all_inboxes`).
        if ($member->reachesAllInboxes()) {
            $member->inboxes()->detach();

            return $member->fresh();
        }

        $valid = HelpDeskInbox::query()
            ->where('help_desk_id', $helpDesk->id)
            ->whereIn('id', array_map('intval', $inboxIds))
            ->pluck('id')
            ->all();

        $member->inboxes()->sync($valid);

        return $member->fresh();
    }

    /**
     * Deactivate or reactivate a member (FR-1.8).
     *
     * Deactivation keeps the row, the role and the inbox access — "member is deactivated while
     * conversations remain assigned" (§9) has to leave those assignments intact and attributable.
     * What changes is that every ability check fails (HelpDeskMember::can), which is why access
     * ends the moment this is saved rather than when data is next rebuilt (§9, last bullet).
     */
    public function setStatus(HelpDeskMember $member, string $status): HelpDeskMember
    {
        if (! in_array($status, [HelpDeskMember::STATUS_ACTIVE, HelpDeskMember::STATUS_INACTIVE], true)) {
            throw ValidationException::withMessages(['status' => 'That is not a Help Desk member status.']);
        }

        $member->forceFill(['status' => $status])->save();

        return $member->fresh();
    }

    /**
     * Remove somebody from the Help Desk.
     *
     * A soft delete, so their replies, notes and assignments survive (acceptance criterion 5).
     * Their inbox access is deliberately LEFT in place: it is part of the record of what they
     * had, and re-adding them restores the row it belongs to.
     */
    public function remove(HelpDeskMember $member): void
    {
        $member->delete();
    }

    private function assertRole(string $role): void
    {
        if (! array_key_exists($role, (array) config('help-desk.roles', []))) {
            throw ValidationException::withMessages(['role' => 'That is not a Help Desk role.']);
        }
    }

    /** The person being added must still be an active member of the workspace. */
    private function assertInWorkspace(HelpDesk $helpDesk, int $userId): void
    {
        $inWorkspace = WorkspaceMembership::query()
            ->where('workspace_id', $helpDesk->tenant_id)
            ->where('user_id', $userId)
            ->where('status', WorkspaceMembership::STATUS_ACTIVE)
            ->exists();

        if (! $inWorkspace) {
            throw ValidationException::withMessages([
                'user_id' => 'That person is not an active member of this workspace.',
            ]);
        }
    }
}
