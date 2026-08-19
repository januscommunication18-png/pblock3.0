<?php

namespace App\Policies;

use App\Models\HelpDesk;
use App\Models\HelpDeskMember;
use App\Models\User;
use App\Services\HelpDesk\HelpDeskAccess;

/**
 * Authorizes Help Desk actions (docs/features/help-desk.md, §13: server-side authorization —
 * hiding the control is not sufficient).
 *
 * Every method delegates to HelpDeskAccess, which owns the most-restrictive-wins chain. The
 * policy exists so controllers read like the rest of the app (`$user->can(...)`), not to hold a
 * second copy of the rules — the sidebar has to ask the same questions without a model in hand,
 * and two implementations of "may they open the Help Desk?" would be one too many.
 */
class HelpDeskPolicy
{
    public function __construct(private readonly HelpDeskAccess $access) {}

    /** May they open the Help Desk area at all? */
    public function view(User $user, HelpDesk $helpDesk): bool
    {
        return $this->access->canOpen($user, $helpDesk->tenant);
    }

    /** May they add, re-role, deactivate or remove members (FR-1.4/1.6/1.8)? */
    public function manageMembers(User $user, HelpDesk $helpDesk): bool
    {
        return $this->access->canAdminister($user, $helpDesk->tenant);
    }

    /** May they create or rename inboxes (FR-1.7)? */
    public function manageInboxes(User $user, HelpDesk $helpDesk): bool
    {
        return $this->access->canAdminister($user, $helpDesk->tenant);
    }

    /**
     * May they act on THAT member?
     *
     * Two rules beyond ordinary member management, both of which exist to keep a Help Desk
     * from being left with nobody who can configure it:
     *
     *   1. a Help Desk Admin may only be acted on by somebody who administers the WORKSPACE —
     *      the authority that could always reach in here (H6) — so admins cannot demote each
     *      other into a Help Desk nobody owns;
     *   2. acting on yourself is not management. Self-demotion and leaving are their own
     *      actions, and allowing them here is how the last Help Desk Admin removes themselves
     *      by accident.
     */
    public function manageMember(User $user, HelpDesk $helpDesk, HelpDeskMember $target): bool
    {
        return ($this->manageMemberFilter($user, $helpDesk))($target);
    }

    /**
     * The same decision, taken once for a whole member list.
     *
     * Both rules above depend only on the ACTOR, not on the row: asking them per member turns a
     * member list into two queries a row (§13 asks for no N+1 in list views). This resolves the
     * actor's standing once and hands back a test to run against each row — one implementation,
     * used by both callers, so the screen cannot disagree with the request it will make.
     *
     * @return \Closure(HelpDeskMember): bool
     */
    public function manageMemberFilter(User $user, HelpDesk $helpDesk): \Closure
    {
        $mayManage = $this->manageMembers($user, $helpDesk);
        $administersWorkspace = $mayManage && $this->access->administersWorkspace($user, $helpDesk->tenant);

        return function (HelpDeskMember $target) use ($user, $mayManage, $administersWorkspace): bool {
            if (! $mayManage) {
                return false;
            }

            if ((int) $target->user_id === (int) $user->id) {
                return false;
            }

            return $target->role !== HelpDeskMember::ROLE_ADMIN || $administersWorkspace;
        };
    }

    /**
     * May they hand out that role?
     *
     * Only somebody who administers the workspace may create a Help Desk Admin — the same
     * asymmetry the workspace roles have, where only an owner may make an owner. A Help Desk
     * Admin promoting another Admin is otherwise a one-way door for whoever set the desk up.
     */
    public function assignRole(User $user, HelpDesk $helpDesk, string $role): bool
    {
        if (! $this->manageMembers($user, $helpDesk)) {
            return false;
        }

        if ($role === HelpDeskMember::ROLE_ADMIN) {
            return $this->access->administersWorkspace($user, $helpDesk->tenant);
        }

        return true;
    }
}
