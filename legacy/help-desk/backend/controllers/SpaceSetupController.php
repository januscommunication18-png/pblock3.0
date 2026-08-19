<?php

namespace App\Http\Controllers\HelpDesk;

use App\Models\HelpDesk;
use App\Models\HelpDeskEmailAddress;
use App\Models\HelpDeskInbox;
use App\Models\HelpDeskMember;
use App\Models\HelpDeskSpace;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\Services\HelpDesk\HelpDeskActivityRecorder;
use App\Services\HelpDesk\HelpDeskEmailAddressManager;
use App\Services\HelpDesk\HelpDeskInviter;
use App\Services\HelpDesk\HelpDeskMemberManager;
use App\Services\HelpDesk\InboundAddressGenerator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Help Desk › a space › Continue to Setup Inbox (the Setup Inbox flow).
 *
 * A four-step, full-page stepper that takes a space from "no inbox" to "an inbox with a
 * generated inbound address, the customer addresses that forward into it, and somebody to read
 * them": name it, add addresses, connect the email, invite the team.
 *
 * A PAGE rather than a modal, and this one is not a style preference. Step 3 asks somebody to go
 * and configure a forwarding rule in Gmail or Microsoft 365 and come back; step 4 asks them to
 * find out a colleague's email address. Both are errands, and a modal is a thing you cannot
 * leave and return to.
 *
 * Every step COMMITS AS IT IS TAKEN — the inbox is created at step 1, each address is stored as
 * it is added, each invitation is sent when it is added. The wizard holds no draft. That is what
 * makes "save progress after each completed step" true rather than aspirational, and it is why
 * abandoning the flow half-way leaves a real, working inbox rather than nothing.
 *
 * Nothing here is a second implementation of anything: the inbox, the addresses, the invitations
 * and the memberships all go through the same services the permanent screens use, so a rule
 * added to one of those cannot be skipped by coming in through the wizard.
 */
class SpaceSetupController extends AreaController
{
    /**
     * GET /help-desk/spaces/{space}/setup-inbox
     *
     * Opens at the first incomplete step, never at step 1 — somebody who got as far as
     * connecting their email and left should not be asked to name their inbox again.
     */
    public function index(HelpDeskSpace $space): View
    {
        $helpDesk = $this->guard($space);

        return $this->page('space-setup', $this->state($helpDesk, $space), [
            'helpDesk' => $helpDesk,
            'spaceModel' => $space,
        ]);
    }

    /**
     * POST /help-desk/spaces/{space}/setup-inbox/name — step 1.
     *
     * Creates the inbox the first time and renames it afterwards, so walking back to step 1 and
     * changing the name is an edit rather than a second inbox. Renaming deliberately does NOT
     * touch the inbound address: that is the flow's own rule, and it is the reason the address
     * carries nothing derived from the name.
     */
    public function name(
        Request $request,
        HelpDeskSpace $space,
        InboundAddressGenerator $addresses,
        HelpDeskActivityRecorder $activity,
    ): JsonResponse {
        $helpDesk = $this->guard($space);

        $name = trim((string) $request->input('name'));

        // Blank-only names are the one input this step has to refuse: an inbox called " " is
        // indistinguishable from one with no name at all on every screen that lists it.
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Give this inbox a name.']);
        }

        if (mb_strlen($name) > 80) {
            throw ValidationException::withMessages(['name' => 'Keep the name to 80 characters or fewer.']);
        }

        $inbox = $space->setupInbox;

        try {
            if ($inbox && (int) $inbox->help_desk_id === (int) $helpDesk->id) {
                $from = (string) $inbox->name;
                $inbox->forceFill(['name' => $name])->save();

                if ($from !== $name) {
                    $activity->inboxRenamed($helpDesk, $this->user(), $inbox, $from);
                }
            } else {
                $inbox = HelpDeskInbox::create([
                    'tenant_id' => $helpDesk->tenant_id,
                    'help_desk_id' => $helpDesk->id,
                    'help_desk_space_id' => $space->id,
                    'name' => $name,
                    'created_by' => $this->user()->id,
                ]);

                $addresses->assign($inbox);
                $activity->inboxCreated($helpDesk, $this->user(), $inbox);

                $space->forceFill(['setup_inbox_id' => $inbox->id])->save();
            }
        } catch (QueryException $e) {
            // Unique name per Help Desk. A collision is a field error, not a 500 — and it can
            // only be caught here, because two administrators can type "Support" at once.
            if (! str_contains(strtolower($e->getMessage()), 'unique')) {
                throw $e;
            }

            throw ValidationException::withMessages([
                'name' => 'An inbox with that name already exists in this Help Desk.',
            ]);
        }

        $space->advanceSetupTo(HelpDeskSpace::STEP_ADDRESSES);

        return $this->stateResponse($helpDesk, $space);
    }

    /** POST /help-desk/spaces/{space}/setup-inbox/addresses — step 2 */
    public function addAddress(Request $request, HelpDeskSpace $space, HelpDeskEmailAddressManager $manager): JsonResponse
    {
        $helpDesk = $this->guard($space);
        $inbox = $this->requireInbox($space);

        $validated = $request->validate([
            'address' => ['required', 'string', 'email', 'max:190'],
            'label' => ['nullable', 'string', 'max:80'],
        ]);

        $connected = $manager->connect(
            $helpDesk,
            $inbox,
            (string) $validated['address'],
            $this->user(),
        );

        $connected->forceFill(['label' => $this->label($validated['label'] ?? null)])->save();

        return $this->stateResponse($helpDesk, $space);
    }

    /** PATCH /help-desk/spaces/{space}/setup-inbox/addresses/{emailAddress} — step 2's Edit */
    public function updateAddress(Request $request, HelpDeskSpace $space, HelpDeskEmailAddress $emailAddress): JsonResponse
    {
        $helpDesk = $this->guard($space);
        $inbox = $this->requireInbox($space);
        abort_unless((int) $emailAddress->help_desk_inbox_id === (int) $inbox->id, 404);

        $validated = $request->validate([
            'address' => ['required', 'string', 'email', 'max:190'],
            'label' => ['nullable', 'string', 'max:80'],
        ]);

        $address = strtolower(trim((string) $validated['address']));

        $clash = HelpDeskEmailAddress::query()
            ->where('help_desk_id', $helpDesk->id)
            ->where('address', $address)
            ->whereKeyNot($emailAddress->id)
            ->exists();

        if ($clash) {
            throw ValidationException::withMessages([
                'address' => 'That address is already connected in this Help Desk.',
            ]);
        }

        /*
         * Changing the address resets its status. Whatever we knew about the old one — that
         * mail arrived from it, that somebody was watching for a test message — was about a
         * different mailbox, and carrying it across would be this screen reporting a forwarding
         * rule works when it has never been tried.
         */
        $changed = $address !== (string) $emailAddress->address;

        $emailAddress->forceFill(array_merge(
            ['address' => $address, 'label' => $this->label($validated['label'] ?? null)],
            $changed ? [
                'status' => HelpDeskEmailAddress::STATUS_SETUP_REQUIRED,
                'verification_started_at' => null,
                'verified_at' => null,
                'last_email_at' => null,
            ] : [],
        ))->save();

        return $this->stateResponse($helpDesk, $space);
    }

    /** DELETE /help-desk/spaces/{space}/setup-inbox/addresses/{emailAddress} — step 2's Remove */
    public function removeAddress(HelpDeskSpace $space, HelpDeskEmailAddress $emailAddress, HelpDeskEmailAddressManager $manager): JsonResponse
    {
        $helpDesk = $this->guard($space);
        $inbox = $this->requireInbox($space);
        abort_unless((int) $emailAddress->help_desk_inbox_id === (int) $inbox->id, 404);

        $manager->remove($helpDesk, $emailAddress, $this->user());

        return $this->stateResponse($helpDesk, $space);
    }

    /**
     * POST /help-desk/spaces/{space}/setup-inbox/verify — step 3's Verify Connection.
     *
     * Starts watching; it sends nothing. The test message has to travel through the customer's
     * own mail provider to prove the forwarding rule works, so the only thing that can send it
     * is a person writing to their support address.
     */
    public function verify(Request $request, HelpDeskSpace $space, HelpDeskEmailAddressManager $manager): JsonResponse
    {
        $helpDesk = $this->guard($space);
        $inbox = $this->requireInbox($space);

        $validated = $request->validate(['email_address_id' => ['required', 'integer']]);

        $emailAddress = HelpDeskEmailAddress::query()
            ->where('help_desk_inbox_id', $inbox->id)
            ->whereKey($validated['email_address_id'])
            ->first();

        abort_unless($emailAddress !== null, 404);

        $manager->verify($emailAddress);

        return $this->stateResponse($helpDesk, $space);
    }

    /**
     * POST /help-desk/spaces/{space}/setup-inbox/connect — step 3's Continue.
     *
     * Marks the step read rather than proven. Proof is a message arriving, which may happen
     * days later or never — a step that could only be completed by mail from outside would be
     * a wizard nobody finishes, and the connection status goes on tracking the truth either way.
     */
    public function connect(HelpDeskSpace $space): JsonResponse
    {
        $helpDesk = $this->guard($space);
        $this->requireInbox($space);

        $space->advanceSetupTo(HelpDeskSpace::STEP_TEAM);

        return $this->stateResponse($helpDesk, $space);
    }

    /**
     * POST /help-desk/spaces/{space}/setup-inbox/team — step 4.
     *
     * One endpoint, two paths, because "invite a team member" means different things depending
     * on whether they already work here: somebody already in the workspace is ADDED to the Help
     * Desk, and a stranger is INVITED to the workspace and the Help Desk together. Making the
     * person typing an address work out which one applies would be this screen exporting its
     * own bookkeeping.
     *
     * Either way the access granted is scoped to THIS space's inboxes — the flow's rule that a
     * member invited here does not thereby reach the other spaces.
     */
    public function addTeamMember(
        Request $request,
        HelpDeskSpace $space,
        HelpDeskInviter $inviter,
        HelpDeskMemberManager $members,
    ): JsonResponse {
        $helpDesk = $this->guard($space);
        $this->requireInbox($space);

        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:190'],
            'role' => ['required', 'string'],
        ]);

        $role = (string) $validated['role'];
        $email = strtolower(trim((string) $validated['email']));

        if (! array_key_exists($role, (array) config('help-desk.roles', []))) {
            throw ValidationException::withMessages(['role' => 'That is not a Help Desk role.']);
        }

        // Only a workspace administrator may create a Help Desk Admin — the same asymmetry the
        // members screen enforces (H10), re-asked here because this is another way in.
        if ($role === HelpDeskMember::ROLE_ADMIN && ! $this->access->administersWorkspace($this->user(), $this->workspace())) {
            throw ValidationException::withMessages([
                'role' => 'Only a workspace owner or admin can add a Help Desk Admin.',
            ]);
        }

        $inboxIds = $this->spaceInboxIdsFor($space);
        $existing = $this->workspaceUserByEmail($email);

        if ($existing) {
            $members->add($helpDesk, $this->user(), $existing->id, $role, $inboxIds);

            return $this->stateResponse($helpDesk, $space, ['added' => $email]);
        }

        $result = $inviter->invite($helpDesk, $this->user(), $email, $role, $inboxIds);

        if ($result['status'] !== 'invited') {
            throw ValidationException::withMessages([
                'email' => $this->inviteMessage($result['status']),
            ]);
        }

        return $this->stateResponse($helpDesk, $space, ['invited' => $email]);
    }

    /**
     * POST /help-desk/spaces/{space}/setup-inbox/finish
     *
     * The team step is deliberately not required: a support desk with one person in it is a
     * real support desk, and blocking the finish line on inviting somebody would make the flow
     * lie about what it needs.
     */
    public function finish(HelpDeskSpace $space): JsonResponse
    {
        $helpDesk = $this->guard($space);
        $inbox = $this->requireInbox($space);

        $space->forceFill([
            'setup_step' => HelpDeskSpace::LAST_STEP,
            'setup_completed_at' => now(),
        ])->save();

        session()->flash(
            'status',
            'Your inbox is ready. Forward your support email to the generated inbound address to start receiving conversations.',
        );

        return response()->json([
            'ok' => true,
            'redirect' => route('help-desk.inboxes.addresses', $inbox),
        ]);
    }

    // ---- state ------------------------------------------------------------------------------

    /**
     * Everything the stepper renders, rebuilt after every write.
     *
     * One payload rather than a partial per action: the screen then renders the server's answer
     * instead of its own guess at what just happened, which is what stops a step's state and
     * the database disagreeing after two tabs are used at once.
     *
     * @return array<string, mixed>
     */
    private function state(HelpDesk $helpDesk, HelpDeskSpace $space): array
    {
        $space = $space->fresh();
        $inbox = $space->setupInbox;

        return [
            'space' => [
                'id' => $space->id,
                'name' => $space->name,
                'initial' => $space->initial(),
                'color' => $space->color,
                'setup_step' => $space->setupStep(),
                'setup_complete' => $space->isSetUp(),
            ],
            'inbox' => $inbox ? [
                'id' => $inbox->id,
                'name' => $inbox->name,
                'inbound_id' => $inbox->inbound_id,
                'inbound_address' => $inbox->inbound_address,
                'instructions' => $inbox->forwardingInstructions(),
            ] : null,
            'addresses' => $inbox ? $this->addressRows($inbox) : [],
            'team' => $this->teamRows($helpDesk, $space),
            'roles' => $this->roleOptions(),
            'endpoints' => [
                'name' => route('help-desk.spaces.setup.name', $space),
                'addresses' => route('help-desk.spaces.setup.addresses', $space),
                'address' => route('help-desk.spaces.setup.addresses.update', ['space' => $space->id, 'emailAddress' => '__ID__']),
                'verify' => route('help-desk.spaces.setup.verify', $space),
                'connect' => route('help-desk.spaces.setup.connect', $space),
                'team' => route('help-desk.spaces.setup.team', $space),
                'finish' => route('help-desk.spaces.setup.finish', $space),
                'cancel' => route('help-desk.spaces'),
            ],
        ];
    }

    /** @param array<string, mixed> $extra */
    private function stateResponse(HelpDesk $helpDesk, HelpDeskSpace $space, array $extra = []): JsonResponse
    {
        return response()->json(array_merge(['ok' => true], $this->state($helpDesk, $space), $extra));
    }

    /**
     * The addresses added so far, as step 2's table and step 3's status list read them.
     *
     * @return array<int, array<string, mixed>>
     */
    private function addressRows(HelpDeskInbox $inbox): array
    {
        return HelpDeskEmailAddress::query()
            ->where('help_desk_inbox_id', $inbox->id)
            ->orderBy('id')
            ->get()
            ->each(fn (HelpDeskEmailAddress $a) => $a->markErrorIfVerificationLapsed())
            ->map(fn (HelpDeskEmailAddress $a) => [
                'id' => $a->id,
                'address' => $a->address,
                'label' => $a->label,
                'name' => $a->displayName(),
                'status' => $a->status,
                'status_label' => $a->statusLabel(),
                'status_hint' => $a->statusHint(),
                'connected' => $a->isConnected(),
                'verified_at' => optional($a->verified_at)->format('M d, Y \a\t H:i'),
                'last_email_at' => optional($a->last_email_at)->diffForHumans(),
            ])
            ->all();
    }

    /**
     * Who can already work in this space, plus who has been invited and not yet arrived.
     *
     * Both, in one list, because step 4 asks a single question — "who handles this?" — and
     * splitting the answer into "members" and "invitations" would make the reader reconcile two
     * tables to find out whether they have already added somebody.
     *
     * @return array<int, array<string, mixed>>
     */
    private function teamRows(HelpDesk $helpDesk, HelpDeskSpace $space): array
    {
        $inboxIds = $this->spaceInboxIdsFor($space);

        $members = HelpDeskMember::query()
            ->where('help_desk_id', $helpDesk->id)
            ->with(['user', 'inboxes'])
            ->get()
            ->filter(fn (HelpDeskMember $m) => $m->reachesAllInboxes()
                || $m->inboxes->pluck('id')->intersect($inboxIds)->isNotEmpty())
            ->map(fn (HelpDeskMember $m) => [
                'id' => 'member-'.$m->id,
                'email' => $m->user?->email,
                'name' => $m->user?->displayName(),
                'role' => $m->role,
                'role_label' => HelpDeskMember::label($m->role),
                'pending' => false,
                // Admins and Managers are here by ROLE, not because this space named them, and
                // the screen says so — otherwise removing them from this space looks possible.
                'all_spaces' => $m->reachesAllInboxes(),
            ])
            ->values();

        $invites = collect($this->pendingInvites($helpDesk))
            ->map(fn (array $invite) => [
                'id' => 'invite-'.$invite['id'],
                'email' => $invite['email'],
                'name' => $invite['email'],
                'role' => $invite['role'],
                'role_label' => $invite['role_label'],
                'pending' => true,
                'all_spaces' => (bool) config("help-desk.roles.{$invite['role']}.all_inboxes"),
            ]);

        return $members->concat($invites)->all();
    }

    /** The inboxes in this space — what access granted at step 4 is scoped to. @return array<int, int> */
    private function spaceInboxIdsFor(HelpDeskSpace $space): array
    {
        return HelpDeskInbox::query()
            ->where('help_desk_space_id', $space->id)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function workspaceUserByEmail(string $email): ?User
    {
        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();

        if (! $user) {
            return null;
        }

        $active = WorkspaceMembership::query()
            ->where('workspace_id', $this->workspace()->id)
            ->where('user_id', $user->id)
            ->where('status', WorkspaceMembership::STATUS_ACTIVE)
            ->exists();

        return $active ? $user : null;
    }

    private function label(?string $label): ?string
    {
        $label = trim((string) $label);

        return $label !== '' ? $label : null;
    }

    /** The same vocabulary the members screen uses for a refused invitation. */
    private function inviteMessage(string $status): string
    {
        return match ($status) {
            'already_member' => 'They are already in this workspace — add them as a member instead.',
            'already_invited' => 'They have already been invited.',
            'no_seats' => 'This workspace has no seats left.',
            default => 'That invitation could not be sent.',
        };
    }

    /**
     * The space, once every question about who may be here has been answered.
     *
     * Setting up an inbox is configuration, so it wants the administration gate rather than the
     * looser "can you open this space" — and the space still has to belong to this Help Desk,
     * which is a 404 rather than a 403 because another workspace's space is not here at all.
     */
    private function guard(HelpDeskSpace $space): HelpDesk
    {
        $helpDesk = $this->helpDesk();
        $this->guardAdminister($helpDesk);
        abort_unless((int) $space->help_desk_id === (int) $helpDesk->id, 404);

        return $helpDesk;
    }

    /**
     * The inbox step 1 created, or a refusal.
     *
     * Steps 2 to 4 all act on it, and reaching them without it means somebody has posted past
     * step 1 — which the stepper does not allow and the server therefore has to.
     */
    private function requireInbox(HelpDeskSpace $space): HelpDeskInbox
    {
        $inbox = $space->setupInbox;

        if (! $inbox) {
            throw ValidationException::withMessages([
                'name' => 'Name the inbox first.',
            ]);
        }

        return $inbox;
    }
}
