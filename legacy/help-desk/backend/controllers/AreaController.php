<?php

namespace App\Http\Controllers\HelpDesk;

use App\Http\Controllers\Controller;
use App\Models\HelpDesk;
use App\Models\HelpDeskInbox;
use App\Models\HelpDeskInvite;
use App\Models\HelpDeskMember;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMembership;
use App\Policies\HelpDeskPolicy;
use App\Services\HelpDesk\HelpDeskAccess;
use App\Services\HelpDesk\HelpDeskProvisioner;
use App\Services\HelpDesk\HelpDeskSpaceContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;

/**
 * Base for every screen inside the Help Desk area (docs/features/help-desk.md).
 *
 * Tenancy is already initialized to the current workspace by `workspace.tenancy`, so every
 * tenant-scoped model below is confined without a single hand-written `where tenant_id`.
 *
 * The two guards are the whole of §13's "enforce server-side authorization; UI hiding is not
 * sufficient" for this area: the rail entry and the buttons are a convenience, and every
 * request re-asks the question regardless of what the page offered.
 */
abstract class AreaController extends Controller
{
    public function __construct(
        protected readonly HelpDeskAccess $access,
        protected readonly HelpDeskProvisioner $provisioner,
        protected readonly HelpDeskSpaceContext $spaceContext,
    ) {}

    /**
     * The space this person is currently working in, or null for all of them
     * (Workspace & Inbox Assignment §13).
     *
     * Read here rather than in each controller so every screen in the area answers "where am
     * I?" the same way — and so a context that has outlived its permission is dropped in one
     * place. See HelpDeskSpaceContext.
     */
    protected function currentSpaceId(): ?int
    {
        return $this->spaceContext->current($this->user(), $this->workspace());
    }

    /**
     * The inbox ids inside the current space, or null when there is no space in context.
     *
     * Null means "do not narrow", which is not the same as an empty array — a space with no
     * inboxes yet must scope its screens to nothing rather than to everything.
     *
     * @return array<int, int>|null
     */
    protected function spaceInboxIds(): ?array
    {
        $spaceId = $this->currentSpaceId();

        if ($spaceId === null) {
            return null;
        }

        return HelpDeskInbox::query()
            ->where('help_desk_space_id', $spaceId)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    protected function user(): User
    {
        return Auth::user();
    }

    protected function workspace(): Workspace
    {
        return $this->user()->currentWorkspace;
    }

    /**
     * The Help Desk this request is about, or a 404.
     *
     * 404 rather than 403 when the app is switched off, consistent with the rest of the app: a
     * refusal that does not confirm what another workspace has enabled.
     */
    protected function helpDesk(): HelpDesk
    {
        abort_unless($this->access->canOpen($this->user(), $this->workspace()), 404);

        $helpDesk = $this->access->helpDeskFor($this->user(), $this->workspace());

        /*
         * A member can reach the area before it has been provisioned only if somebody put them
         * in a Help Desk that was then deleted — provisioning happens on an administrator's
         * first visit, and membership cannot exist without it.
         */
        abort_unless($helpDesk !== null, 404);

        return $helpDesk;
    }

    /** Configuring the Help Desk — members, inboxes, settings. 403, because the area IS visible. */
    protected function guardAdminister(HelpDesk $helpDesk): void
    {
        abort_unless($this->user()->can('manageMembers', $helpDesk), 403);
    }

    /** Render a Help Desk screen: the app shell + a mounted Vue component. */
    protected function page(string $view, array $bootstrap = [], array $extra = []): View
    {
        return view("help-desk.{$view}", array_merge([
            'user' => $this->user(),
            'workspace' => $this->workspace(),
            'bootstrap' => $bootstrap,
        ], $extra));
    }

    /**
     * The member list as the screen shows it (FR-1.4/1.6/1.7/1.8).
     *
     * Eager-loads the user and the named inboxes — §13 asks for no N+1 in list views, and a
     * member row renders both.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function memberRows(HelpDesk $helpDesk): array
    {
        /*
         * The actor's standing, resolved once for the whole list rather than per row — see
         * HelpDeskPolicy::manageMemberFilter. It is the same decision `manageMember` makes for
         * a single request, so the buttons the screen offers and the requests the server will
         * accept cannot drift apart.
         */
        $actor = $this->user();
        $mayManage = (new HelpDeskPolicy($this->access))->manageMemberFilter($actor, $helpDesk);

        return HelpDeskMember::query()
            ->where('help_desk_id', $helpDesk->id)
            ->with(['user', 'inboxes'])
            ->get()
            /*
             * Highest authority first, then alphabetically — the order the role picker lists
             * them in, so the member list reads the same way. One sort key rather than two
             * passes: ranks top out at 50, so `100 - rank` zero-padded sorts descending by rank
             * and ascending by name in a single string comparison.
             */
            ->sortBy(fn (HelpDeskMember $m) => sprintf('%03d|%s', 100 - $m->rank(), strtolower((string) $m->user?->displayName())))
            ->values()
            ->map(fn (HelpDeskMember $m) => [
                'id' => $m->id,
                'user_id' => $m->user_id,
                'name' => $m->user?->displayName(),
                'email' => $m->user?->email,
                'initial' => $m->user?->initial(),
                'role' => $m->role,
                'role_label' => HelpDeskMember::label($m->role),
                'status' => $m->status,
                'all_inboxes' => $m->reachesAllInboxes(),
                'inbox_ids' => $m->inboxes->pluck('id')->all(),
                'inboxes' => $m->inboxes->pluck('name')->all(),
                'added' => optional($m->created_at)->format('M d, Y'),
                'is_self' => (int) $m->user_id === (int) $actor->id,

                // Whether the row offers controls is decided by the SAME policy that would
                // refuse the request, so the screen cannot show an action the server rejects —
                // and the server still re-checks, because a hidden button is not a guard (§13).
                'can_manage' => $mayManage($m),
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    protected function inboxRows(HelpDesk $helpDesk): array
    {
        return HelpDeskInbox::query()
            ->where('help_desk_id', $helpDesk->id)
            ->orderBy('name')
            ->get()
            ->map(fn (HelpDeskInbox $i) => ['id' => $i->id, 'name' => $i->name])
            ->all();
    }

    /**
     * Workspace members who could still be added (§4, "Add Existing Workspace Member").
     *
     * Excludes anybody who already has a membership, INCLUDING a soft-deleted one — adding them
     * restores that row rather than creating a second, so offering them as a fresh choice would
     * describe something the manager does not do.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function assignableUsers(HelpDesk $helpDesk): array
    {
        $taken = HelpDeskMember::withTrashed()
            ->where('help_desk_id', $helpDesk->id)
            ->pluck('user_id')
            ->all();

        return WorkspaceMembership::query()
            ->where('workspace_id', $this->workspace()->id)
            ->where('status', WorkspaceMembership::STATUS_ACTIVE)
            ->whereNotIn('user_id', $taken)
            ->with('user')
            ->get()
            ->sortBy(fn (WorkspaceMembership $m) => strtolower((string) $m->user?->displayName()))
            ->values()
            ->map(fn (WorkspaceMembership $m) => [
                'id' => $m->user_id,
                'name' => $m->user?->displayName(),
                'email' => $m->user?->email,
                'initial' => $m->user?->initial(),
                'workspace_role' => $m->role,
            ])
            ->all();
    }

    /**
     * Outstanding coworker invitations (FR-1.5).
     *
     * Only the ones still worth acting on: an invite whose workspace invitation has been
     * accepted, revoked or has expired is history, and listing it beside live ones invites
     * somebody to revoke something that is already gone.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function pendingInvites(HelpDesk $helpDesk): array
    {
        return HelpDeskInvite::query()
            ->where('help_desk_id', $helpDesk->id)
            ->whereNull('redeemed_at')
            ->whereHas('invitation', fn ($q) => $q->where('status', WorkspaceInvitation::STATUS_PENDING))
            ->with(['inviter', 'invitation'])
            ->latest('id')
            ->get()
            ->map(fn (HelpDeskInvite $i) => [
                'id' => $i->id,
                'email' => $i->email,
                'role' => $i->role,
                'role_label' => HelpDeskMember::label($i->role),
                'invited_by' => $i->inviter?->displayName() ?? '—',
                'invited' => optional($i->created_at)->format('M d, Y'),
                'expires' => optional($i->invitation?->expires_at)->format('M d, Y'),
            ])
            ->all();
    }

    /**
     * The five roles, as the pickers offer them (FR-1.6).
     *
     * @return array<int, array<string, mixed>>
     */
    protected function roleOptions(): array
    {
        return collect(HelpDeskMember::roles())
            ->map(fn (string $role) => [
                'value' => $role,
                'label' => HelpDeskMember::label($role),
                'desc' => (string) config("help-desk.roles.{$role}.description"),
                'all_inboxes' => (bool) config("help-desk.roles.{$role}.all_inboxes"),
            ])
            ->all();
    }
}
