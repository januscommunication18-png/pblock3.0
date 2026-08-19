<?php

namespace App\Http\Controllers\HelpDesk;

use App\Models\HelpDeskMember;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * The Help Desk front door (docs/features/help-desk.md) — Phase 1, slices 1–2.
 *
 * Enabling the app puts this screen in the rail; being a Help Desk member (or the workspace
 * owner/admin who sets one up) is what gets you through it. Conversations and inboxes are
 * Phases 2–3, so what this screen does today is tell you where you stand and, if you may
 * configure it, point at the member list.
 */
class HelpDeskController extends AreaController
{
    /** GET /help-desk */
    public function index(): View|RedirectResponse
    {
        // Refuses on its own, whatever the rail chose to show (§13). Provisions the Help Desk
        // on an administrator's first visit.
        $helpDesk = $this->helpDesk();

        /*
         * A Help Desk nobody has set up yet sends its administrator to the wizard (FR-1.3)
         * rather than to an overview of nothing. Everybody else stays here: a member has
         * nothing to set up, and being redirected into somebody else's configuration screen is
         * how a wizard becomes a wall.
         */
        if ($helpDesk->setup_completed_at === null && $this->user()->can('manageMembers', $helpDesk)) {
            return redirect()->route('help-desk.setup');
        }

        $member = $this->access->membership($this->user(), $this->workspace());

        return $this->page('index', [], [
            'helpDesk' => $helpDesk,
            'member' => $member,
            'roleLabel' => $member ? HelpDeskMember::label($member->role) : null,
            'canManage' => $this->user()->can('manageMembers', $helpDesk),
            'inboxCount' => count($this->inboxRows($helpDesk)),
            'memberCount' => count($this->memberRows($helpDesk)),
        ]);
    }
}
