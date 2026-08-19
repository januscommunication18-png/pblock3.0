<?php

namespace App\Http\Controllers\HelpDesk;

use App\Http\Requests\HelpDesk\StoreInboxRequest;
use App\Models\HelpDesk;
use App\Models\HelpDeskEmailAddress;
use App\Models\HelpDeskInbox;
use App\Models\HelpDeskMember;
use App\Models\HelpDeskSpace;
use App\Services\HelpDesk\HelpDeskActivityRecorder;
use App\Services\HelpDesk\InboundAddressGenerator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Help Desk › Settings › Inboxes (docs/features/help-desk.md — Phase 2, slice 1:
 * FR-2.1 shared inboxes, FR-2.2 inbound address, FR-2.3 outbound identity, FR-2.5 default
 * assignee, FR-2.6 inbox permissions).
 *
 * Create and configure, and nothing else. DELETING an inbox is deliberately absent: an inbox
 * holds conversations, and "what happens to them" is a product question the phase does not
 * answer. When it does, it will be an archive rather than a delete.
 */
class InboxController extends AreaController
{
    /** GET /help-desk/settings/inboxes */
    public function index(Request $request): View
    {
        $helpDesk = $this->helpDesk();
        $this->guardAdminister($helpDesk);

        /*
         * §11's "create an Inbox directly from a Workspace": the space screen links here with
         * `?space=`, which opens the form with that space already chosen, so the new inbox is
         * assigned to it without anybody having to remember to.
         */
        $fromSpace = (int) $request->integer('space');
        $fromSpace = $fromSpace && $this->spaceBelongs($helpDesk, $fromSpace) ? $fromSpace : null;

        return $this->page('settings.inboxes', [
            'inboxes' => $this->inboxDetail($helpDesk),
            'members' => $this->assignableMembers($helpDesk),
            'spaces' => $this->spaceOptions($helpDesk),
            'new_in_space' => $fromSpace,
            'endpoints' => [
                'store' => route('help-desk.inboxes.store'),
                'inbox' => route('help-desk.inboxes.update', ['inbox' => '__ID__']),
                'spaces' => route('help-desk.spaces'),
            ],
        ], ['helpDesk' => $helpDesk]);
    }

    /** POST /help-desk/settings/inboxes */
    public function store(
        StoreInboxRequest $request,
        HelpDeskActivityRecorder $activity,
        InboundAddressGenerator $addresses,
    ): JsonResponse {
        $helpDesk = $this->helpDesk();
        $this->guardAdminister($helpDesk);

        $inbox = $this->guardDuplicate(fn () => HelpDeskInbox::create(
            $this->attributes($request, $helpDesk) + [
                'tenant_id' => $helpDesk->tenant_id,
                'help_desk_id' => $helpDesk->id,
                'created_by' => $this->user()->id,
            ],
        ));

        // Every inbox receives at a generated address from the moment it exists (Inbound Email
        // requirements §2) — it is not a field somebody fills in afterwards, and an inbox that
        // nothing can be forwarded to is not an inbox.
        $addresses->assign($inbox);

        $activity->inboxCreated($helpDesk, $this->user(), $inbox);

        return $this->listResponse($helpDesk, ['inbox_id' => $inbox->id]);
    }

    /** PATCH /help-desk/settings/inboxes/{inbox} */
    public function update(StoreInboxRequest $request, HelpDeskInbox $inbox, HelpDeskActivityRecorder $activity): JsonResponse
    {
        $helpDesk = $this->helpDesk();
        $this->guardAdminister($helpDesk);
        abort_unless((int) $inbox->help_desk_id === (int) $helpDesk->id, 404);

        $from = (string) $inbox->name;

        $this->guardDuplicate(fn () => $inbox->forceFill($this->attributes($request, $helpDesk))->save());

        if ($from !== $inbox->name) {
            $activity->inboxRenamed($helpDesk, $this->user(), $inbox, $from);
        }

        return $this->listResponse($helpDesk);
    }

    /**
     * POST /help-desk/settings/inboxes/{inbox}/inbound-address
     *
     * §2's "regenerated only through an explicit administrative action". Its own route and its
     * own button, because it is not a setting — it is a break: every forwarding rule pointing at
     * the old address stops working the moment this returns, in mail providers this application
     * cannot see and cannot warn.
     */
    public function regenerateAddress(
        HelpDeskInbox $inbox,
        InboundAddressGenerator $addresses,
        HelpDeskActivityRecorder $activity,
    ): JsonResponse {
        $helpDesk = $this->helpDesk();
        $this->guardAdminister($helpDesk);
        abort_unless((int) $inbox->help_desk_id === (int) $helpDesk->id, 404);

        $from = (string) $inbox->inbound_address;

        $addresses->assign($inbox);

        $activity->inboundAddressRegenerated($helpDesk, $this->user(), $inbox, $from);

        return $this->listResponse($helpDesk, ['inbound_address' => $inbox->inbound_address]);
    }

    /**
     * The writable columns, with the default assignee checked against THIS Help Desk.
     *
     * A member id from another workspace, or one that has been removed, becomes null rather
     * than an error: it is a stale screen, not an attack, and refusing the whole save would
     * lose the administrator's other edits with it.
     *
     * @return array<string, mixed>
     */
    private function attributes(StoreInboxRequest $request, HelpDesk $helpDesk): array
    {
        $assignee = $request->validated('default_assignee_id');

        $valid = $assignee !== null && HelpDeskMember::query()
            ->where('help_desk_id', $helpDesk->id)
            ->whereKey($assignee)
            ->active()
            ->exists();

        $space = $request->validated('help_desk_space_id');

        return [
            'name' => trim((string) $request->validated('name')),
            // `inbound_address` is deliberately not here: it is generated, and renaming an inbox
            // must not change an address somebody's mail provider is forwarding to (§2).
            //
            // The space is, and a value this Help Desk does not own becomes null rather than an
            // error — the same treatment the default assignee gets, for the same reason.
            'help_desk_space_id' => $space && $this->spaceBelongs($helpDesk, (int) $space) ? (int) $space : null,
            'outbound_from_name' => $request->validated('outbound_from_name'),
            'outbound_from_address' => $request->validated('outbound_from_address'),
            'default_assignee_id' => $valid ? (int) $assignee : null,
        ];
    }

    /**
     * Turn the unique-name constraint into a message.
     *
     * The database owns the rule (unique per Help Desk) because two administrators can create
     * "Billing" at the same instant and a pre-flight query cannot see the other one; this is
     * what turns that collision into a field error rather than a 500.
     */
    private function guardDuplicate(callable $write): mixed
    {
        try {
            return $write();
        } catch (QueryException $e) {
            if (! str_contains(strtolower($e->getMessage()), 'unique')) {
                throw $e;
            }

            throw ValidationException::withMessages([
                'name' => 'An inbox with that name already exists.',
            ]);
        }
    }

    /**
     * Inboxes with everything the settings screen shows (FR-2.1/2.2/2.3/2.5/2.6).
     *
     * @return array<int, array<string, mixed>>
     */
    private function inboxDetail(HelpDesk $helpDesk): array
    {
        return HelpDeskInbox::query()
            ->where('help_desk_id', $helpDesk->id)
            // Counted and loaded in one pass — §13 asks for no N+1 in list views, and this list
            // shows both who can reach an inbox and how busy it is.
            ->with(['defaultAssignee.user', 'space'])
            ->withCount([
                'members',
                'conversations',
                'emailAddresses',
                // Connected ones separately: "3 addresses" and "3 addresses, none of them
                // receiving anything" are the same number and completely different situations.
                'emailAddresses as connected_addresses_count' => fn ($q) => $q
                    ->where('status', HelpDeskEmailAddress::STATUS_CONNECTED),
            ])
            ->orderBy('name')
            ->get()
            ->map(fn (HelpDeskInbox $i) => [
                'id' => $i->id,
                'name' => $i->name,
                'inbound_address' => $i->inbound_address,
                // Where this inbox lives (§7). Null is a real answer and the screen says so:
                // an unassigned inbox still receives mail, in a space nobody is looking at.
                'space_id' => $i->help_desk_space_id,
                'space' => $i->space?->name,
                'outbound_from_name' => $i->outbound_from_name,
                'outbound_from_address' => $i->outbound_from_address,
                'default_assignee_id' => $i->default_assignee_id,
                'default_assignee' => $i->defaultAssignee?->user?->displayName(),
                // Members named for this inbox. Admins and Managers reach it without a row, so
                // this is a count of restricted members, not of everyone who can open it.
                'members_count' => $i->members_count,
                'conversations_count' => $i->conversations_count,
                'email_addresses_count' => $i->email_addresses_count,
                'connected_addresses_count' => $i->connected_addresses_count,
                'sender' => $i->sender(),
                'addresses_url' => route('help-desk.inboxes.addresses', $i),
                'regenerate_url' => route('help-desk.inboxes.address.regenerate', $i),
            ])
            ->all();
    }

    /**
     * The spaces an inbox can be put in (§12's "Inbox → Settings → Workspace").
     *
     * Archived ones are left out: filing a live inbox into a space somebody has finished with
     * is how mail ends up somewhere nobody looks.
     *
     * @return array<int, array<string, mixed>>
     */
    private function spaceOptions(HelpDesk $helpDesk): array
    {
        return HelpDeskSpace::query()
            ->where('help_desk_id', $helpDesk->id)
            ->whereNull('archived_at')
            ->orderBy('name')
            ->get()
            ->map(fn (HelpDeskSpace $s) => ['id' => $s->id, 'name' => $s->name])
            ->all();
    }

    /** Is that space one of this Help Desk's, and still live? */
    private function spaceBelongs(HelpDesk $helpDesk, int $spaceId): bool
    {
        return HelpDeskSpace::query()
            ->where('help_desk_id', $helpDesk->id)
            ->whereNull('archived_at')
            ->whereKey($spaceId)
            ->exists();
    }

    /**
     * Who can be an inbox's default assignee (FR-2.5).
     *
     * Active members only, and a member rather than a user: assignment is a Help Desk fact, and
     * offering somebody who is not in the Help Desk would be offering an assignment that cannot
     * be honoured.
     *
     * @return array<int, array<string, mixed>>
     */
    private function assignableMembers(HelpDesk $helpDesk): array
    {
        return HelpDeskMember::query()
            ->where('help_desk_id', $helpDesk->id)
            ->active()
            ->with('user')
            ->get()
            ->sortBy(fn (HelpDeskMember $m) => strtolower((string) $m->user?->displayName()))
            ->values()
            ->map(fn (HelpDeskMember $m) => [
                'id' => $m->id,
                'name' => $m->user?->displayName(),
                'email' => $m->user?->email,
                'initial' => $m->user?->initial(),
                'role_label' => HelpDeskMember::label($m->role),
            ])
            ->all();
    }

    /** @param array<string, mixed> $extra */
    private function listResponse(HelpDesk $helpDesk, array $extra = []): JsonResponse
    {
        return response()->json(array_merge([
            'ok' => true,
            'inboxes' => $this->inboxDetail($helpDesk),
            // The members screen reads this list too — it is what inbox access is granted from.
            'members' => $this->memberRows($helpDesk),
        ], $extra));
    }
}
