<?php

namespace App\Services\HelpDesk;

use App\Models\HelpDesk;
use App\Models\HelpDeskInbox;
use App\Models\HelpDeskMember;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\WorkspaceApps;

/**
 * The one place that answers "may this person do that in this workspace's Help Desk?"
 * (docs/features/help-desk.md, FR-1.4/1.6/1.7/1.8).
 *
 * §5 of the phase document is the rule this class exists to keep: workspace role, project role,
 * Help Desk role and inbox access are evaluated INDEPENDENTLY, and the most restrictive
 * applicable permission wins. So every answer here is a chain of ANDs, in this order:
 *
 *   1. the workspace has Help Desk switched on at all;
 *   2. the person is an active member of the WORKSPACE (losing that loses everything downstream);
 *   3. they have an active Help Desk membership — or they administer the workspace (H6);
 *   4. their Help Desk ROLE carries the ability being asked about;
 *   5. for anything inbox-shaped, that inbox is one they were given (H7).
 *
 * Every screen, route and rail entry asks this class rather than re-deriving the chain, because
 * a second derivation is a second answer waiting to disagree with this one.
 */
class HelpDeskAccess
{
    public function __construct(
        private readonly WorkspaceApps $apps,
        private readonly HelpDeskProvisioner $provisioner,
    ) {}

    /** Is the app switched on for this workspace at all (FR-1.1)? */
    public function enabled(?Workspace $workspace): bool
    {
        return $workspace !== null && $this->apps->isEnabled($workspace, 'helpdesk');
    }

    /**
     * This person's Help Desk membership, whatever its status — null if they have none.
     *
     * Queried by explicit `tenant_id` rather than through the tenant scope: the app sidebar
     * asks on every authenticated page, including ones rendered before `workspace.tenancy` has
     * initialized a context, where the scope would match nothing and every member would look
     * like a stranger. The explicit `where` IS the isolation (CLAUDE.md §7), and it is the same
     * reason WorkspaceApps reads its flags this way.
     */
    public function membership(?User $user, ?Workspace $workspace): ?HelpDeskMember
    {
        if (! $user || ! $workspace) {
            return null;
        }

        return HelpDeskMember::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $workspace->id)
            ->where('user_id', $user->id)
            ->whereNull('deleted_at')
            ->first();
    }

    /**
     * Does this person administer the workspace itself?
     *
     * Owners and admins may set the Help Desk up and decide who works in it, WITHOUT holding a
     * Help Desk role (decision H6). Somebody has to be able to add the first member, and the
     * alternative — seeding whoever flipped the toggle as a Help Desk Admin — would make
     * enabling the app grant Help Desk access, which the first acceptance criterion forbids.
     *
     * This is administrative authority only. It carries no operational ability: `allows()`
     * below reads the Help Desk ROLE, so a workspace admin who is not a member may configure
     * the Help Desk and never send a customer a reply.
     */
    public function administersWorkspace(?User $user, ?Workspace $workspace): bool
    {
        if (! $user || ! $workspace) {
            return false;
        }

        return $user->can('manageSettings', $workspace);
    }

    /** Is this person still an active member of the WORKSPACE (step 2 of the chain)? */
    private function inWorkspace(User $user, Workspace $workspace): bool
    {
        return WorkspaceMembership::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $user->id)
            ->where('status', WorkspaceMembership::STATUS_ACTIVE)
            ->exists();
    }

    /**
     * May this person open the Help Desk at all — the rail entry and the area itself?
     *
     * "When enabled, Help Desk appears in the left navigation only for members with Help Desk
     * access" (§4). Enabling the app on its own puts nobody in here (H5).
     */
    public function canOpen(?User $user, ?Workspace $workspace): bool
    {
        if (! $user || ! $this->enabled($workspace) || ! $this->inWorkspace($user, $workspace)) {
            return false;
        }

        /*
         * Workspace authority first, and independently of any membership row: an owner who
         * deactivated their own Help Desk membership would otherwise be locked out of the very
         * screen that could switch it back on.
         */
        return $this->administersWorkspace($user, $workspace)
            || (bool) $this->membership($user, $workspace)?->isActive();
    }

    /** May this person configure the Help Desk — inboxes, members, settings? */
    public function canAdminister(?User $user, ?Workspace $workspace): bool
    {
        if (! $user || ! $this->enabled($workspace) || ! $this->inWorkspace($user, $workspace)) {
            return false;
        }

        return $this->administersWorkspace($user, $workspace)
            || (bool) $this->membership($user, $workspace)?->can('manage_members');
    }

    /**
     * May this person do that, by Help Desk role (FR-1.6)?
     *
     * Workspace authority is NOT a substitute here — an ability like `reply` belongs to the
     * Help Desk role model, and granting it to whoever administers the workspace would collapse
     * the two role systems §5 requires be kept apart.
     */
    public function allows(?User $user, ?Workspace $workspace, string $ability): bool
    {
        if (! $user || ! $this->enabled($workspace) || ! $this->inWorkspace($user, $workspace)) {
            return false;
        }

        return (bool) $this->membership($user, $workspace)?->can($ability);
    }

    /**
     * Which inboxes this person may open (FR-1.7).
     *
     * `null` means "all of them" — the answer for Admins and Managers, and the reason it is not
     * an array: a list of every current inbox would be right until the next inbox is created.
     * An empty array means a member who has been given none, which is not the same thing and
     * must not be allowed to read as "everything".
     *
     * @return array<int, int>|null
     */
    public function inboxIds(?User $user, ?Workspace $workspace): ?array
    {
        $member = $this->membership($user, $workspace);

        if (! $member || ! $member->isActive()) {
            return [];
        }

        if ($member->reachesAllInboxes()) {
            return null;
        }

        return $member->inboxes()->withoutGlobalScopes()->pluck('help_desk_inboxes.id')->all();
    }

    /**
     * The inboxes this person may actually open, as the Help Desk navigation lists them.
     *
     * Follows `inboxIds` exactly, which means a workspace admin who is not a Help Desk member
     * sees NONE — they configure the desk (H6), they do not work in it. An administrator
     * looking at an empty list is the honest answer to "what can I open here?", and the member
     * screen is one click away.
     *
     * @return array<int, array<string, mixed>>
     */
    public function visibleInboxes(?User $user, ?Workspace $workspace): array
    {
        if (! $this->canOpen($user, $workspace)) {
            return [];
        }

        $helpDesk = $this->provisioner->existing($workspace);

        if (! $helpDesk) {
            return [];
        }

        $allowed = $this->inboxIds($user, $workspace);

        $query = HelpDeskInbox::query()
            ->withoutGlobalScopes()
            ->where('help_desk_id', $helpDesk->id);

        // null is "every inbox"; a list — including an empty one — is exactly those.
        if ($allowed !== null) {
            $query->whereIn('id', $allowed);
        }

        return $query->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (HelpDeskInbox $inbox) => ['id' => $inbox->id, 'name' => $inbox->name])
            ->all();
    }

    /** May this person open that specific inbox? */
    public function canOpenInbox(?User $user, ?Workspace $workspace, int $inboxId): bool
    {
        if (! $this->canOpen($user, $workspace)) {
            return false;
        }

        $allowed = $this->inboxIds($user, $workspace);

        return $allowed === null || in_array($inboxId, $allowed, true);
    }

    /** The Help Desk itself, provisioned on first use by somebody entitled to set it up. */
    public function helpDeskFor(User $user, Workspace $workspace): ?HelpDesk
    {
        if ($this->administersWorkspace($user, $workspace)) {
            return $this->provisioner->for($workspace, $user);
        }

        return $this->provisioner->existing($workspace);
    }
}
