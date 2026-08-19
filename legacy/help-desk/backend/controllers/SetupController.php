<?php

namespace App\Http\Controllers\HelpDesk;

use App\Models\HelpDeskMember;
use App\Services\HelpDesk\HelpDeskActivityRecorder;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The first-time setup wizard (docs/features/help-desk.md, FR-1.3).
 *
 * §4's sequence is "Enable > Create first inbox > Invite team > Assign roles > Configure basic
 * settings". Enabling happens in Workspace Settings, so the wizard picks up from there and
 * walks the remaining four in one screen.
 *
 * It writes through the SAME endpoints the members and inbox screens use rather than
 * duplicating them: a wizard with its own save paths is a second set of rules that can disagree
 * with the ones the screens enforce. All this controller owns is the Help Desk's own name and
 * the moment setup was declared finished.
 */
class SetupController extends AreaController
{
    /** GET /help-desk/setup */
    public function index(): View|RedirectResponse
    {
        $helpDesk = $this->helpDesk();
        $this->guardAdminister($helpDesk);

        // Finished is finished: the wizard is not a settings screen, and the pieces it walks
        // through all have permanent homes.
        if ($helpDesk->setup_completed_at !== null) {
            return redirect()->route('help-desk.index');
        }

        return $this->page('setup', [
            'name' => $helpDesk->name,
            'members' => $this->memberRows($helpDesk),
            'pending' => $this->pendingInvites($helpDesk),
            'inboxes' => $this->inboxRows($helpDesk),
            'assignable' => $this->assignableUsers($helpDesk),
            'roles' => $this->roleOptions(),
            'canAssignAdmin' => $this->user()->can('assignRole', [$helpDesk, HelpDeskMember::ROLE_ADMIN]),
            'endpoints' => [
                'settings' => route('help-desk.setup.update'),
                'complete' => route('help-desk.setup.complete'),
                'store' => route('help-desk.members.store'),
                'member' => route('help-desk.members.update', ['member' => '__ID__']),
                'invite' => route('help-desk.invites.store'),
                'inviteItem' => route('help-desk.invites.destroy', ['invite' => '__ID__']),
                'inboxes' => route('help-desk.inboxes.store'),
                'inbox' => route('help-desk.inboxes.update', ['inbox' => '__ID__']),
                'done' => route('help-desk.index'),
            ],
        ], ['helpDesk' => $helpDesk]);
    }

    /** PATCH /help-desk/setup — the "basic settings" step, which in Phase 1 is the name. */
    public function update(Request $request): JsonResponse
    {
        $helpDesk = $this->helpDesk();
        $this->guardAdminister($helpDesk);

        $validated = $request->validate(['name' => ['required', 'string', 'max:120']]);

        $helpDesk->forceFill(['name' => trim($validated['name'])])->save();

        return response()->json(['ok' => true, 'name' => $helpDesk->name]);
    }

    /**
     * POST /help-desk/setup/complete
     *
     * Idempotent: a double submit, a refresh, or somebody finishing the wizard in two tabs must
     * not move the date or write a second activity entry.
     */
    public function complete(HelpDeskActivityRecorder $activity): JsonResponse
    {
        $helpDesk = $this->helpDesk();
        $this->guardAdminister($helpDesk);

        if ($helpDesk->setup_completed_at === null) {
            $helpDesk->forceFill(['setup_completed_at' => now()])->save();
            $activity->setupCompleted($helpDesk, $this->user());
        }

        return response()->json(['ok' => true, 'redirect' => route('help-desk.index')]);
    }
}
