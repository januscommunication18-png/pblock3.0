<?php

namespace App\Http\Controllers\HelpDesk;

use App\Http\Requests\HelpDesk\StoreSpaceRequest;
use App\Models\HelpDesk;
use App\Models\HelpDeskConversation;
use App\Models\HelpDeskEmailAddress;
use App\Models\HelpDeskInbox;
use App\Models\HelpDeskMember;
use App\Models\HelpDeskSpace;
use App\Services\HelpDesk\HelpDeskSpaceContext;
use App\Services\HelpDesk\HelpDeskSpaceManager;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Help Desk › Spaces (Workspace & Inbox Assignment requirements §5, §8, §9, §10, §12, §13, §19).
 *
 * A space is a separate support operation inside one Help Desk — a brand, a business unit, a
 * region — owning its own inboxes and, through them, its own conversations.
 *
 * Three screens, each with a URL of its own, because §5 and §9 ask for pages rather than
 * dialogs: the list, the creation page, and the space itself.
 *
 * Who may do what:
 *   - OPENING a space follows inbox access (H38) — you see the spaces holding an inbox you can
 *     open, and workspace administrators see all of them because they configure the desk (H6);
 *   - CHANGING one — creating, editing, assigning inboxes, archiving — is administration, and
 *     re-asks `guardAdminister` on every action (§13: hiding the control is not authorization).
 */
class SpaceController extends AreaController
{
    /** GET /help-desk/spaces — §8, and §19's empty state when there are none. */
    public function index(Request $request): View
    {
        $helpDesk = $this->helpDesk();
        $canManage = $this->user()->can('manageMembers', $helpDesk);

        // Archived ones are out of the way until asked for (§8's Archive is not a delete).
        $showArchived = $request->boolean('archived');

        return $this->page('spaces', [
            'spaces' => $this->spaceRows($helpDesk, $showArchived),
            'unassigned' => $this->unassignedInboxes($helpDesk),
            'show_archived' => $showArchived,
            'can_manage' => $canManage,
            'type_suggestions' => $this->typeSuggestions(),
            'endpoints' => [
                'create' => route('help-desk.spaces.create'),
                'space' => route('help-desk.spaces.show', ['space' => '__ID__']),
                'update' => route('help-desk.spaces.update', ['space' => '__ID__']),
                'archive' => route('help-desk.spaces.archive', ['space' => '__ID__']),
                'restore' => route('help-desk.spaces.restore', ['space' => '__ID__']),
                'switch' => route('help-desk.spaces.switch'),
            ],
        ], ['helpDesk' => $helpDesk]);
    }

    /**
     * GET /help-desk/spaces/new — §5's dedicated creation page.
     *
     * Carries every inbox with its current assignment, which is §6's selector in full: search,
     * multi-select, the inbox name, the address it receives at, and where it is now. The last of
     * those is the one that matters — assigning an inbox that already belongs somewhere is a
     * MOVE, and the page has to say so before it happens rather than afterwards.
     */
    public function create(): View
    {
        $helpDesk = $this->helpDesk();
        $this->guardAdminister($helpDesk);

        return $this->page('space-new', [
            'inboxes' => $this->assignableInboxes($helpDesk),
            'type_suggestions' => $this->typeSuggestions(),
            'colors' => self::COLORS,
            'endpoints' => [
                'store' => route('help-desk.spaces.store'),
                'cancel' => route('help-desk.spaces'),
            ],
        ], ['helpDesk' => $helpDesk]);
    }

    /** POST /help-desk/spaces — §5, §6 */
    public function store(StoreSpaceRequest $request, HelpDeskSpaceManager $spaces): JsonResponse
    {
        $helpDesk = $this->helpDesk();
        $this->guardAdminister($helpDesk);

        $space = $spaces->create(
            $helpDesk,
            $this->user(),
            $this->attributes($request),
            (array) ($request->validated('inbox_ids') ?? []),
        );

        session()->flash('status', "Space \"{$space->name}\" created.");

        return response()->json([
            'ok' => true,
            'redirect' => route('help-desk.spaces.show', $space),
        ]);
    }

    /**
     * GET /help-desk/spaces/{space} — §9's detail page and §10's inbox section.
     *
     * Opening a space also makes it the CONTEXT (§13): you are inside it now, so the sidebar,
     * the conversation list and the inbox list follow you here rather than staying wherever the
     * switcher was last left.
     */
    public function show(HelpDeskSpace $space, HelpDeskSpaceContext $context): View
    {
        $helpDesk = $this->helpDesk();
        $this->guardSpace($helpDesk, $space);
        abort_unless($this->access->canOpenSpace($this->user(), $this->workspace(), $space->id), 403);

        $context->set($this->user(), $this->workspace(), $space->id);

        $canManage = $this->user()->can('manageMembers', $helpDesk);

        return $this->page('space', [
            'space' => $this->spaceRow($space, $helpDesk),
            'inboxes' => $this->inboxRowsFor($space),
            // Only what an administrator can actually act on: the assign dialog is theirs.
            'assignable' => $canManage ? $this->assignableInboxes($helpDesk, $space) : [],
            'can_manage' => $canManage,
            'type_suggestions' => $this->typeSuggestions(),
            'endpoints' => [
                'update' => route('help-desk.spaces.update', $space),
                'assign' => route('help-desk.spaces.assign', $space),
                'archive' => route('help-desk.spaces.archive', $space),
                'conversations' => route('help-desk.conversations'),
                'inboxes' => route('help-desk.inboxes'),
                'members' => route('help-desk.members'),
                'new_inbox' => route('help-desk.inboxes').'?space='.$space->id,
            ],
        ], ['helpDesk' => $helpDesk, 'spaceModel' => $space]);
    }

    /** PATCH /help-desk/spaces/{space} — §8's Edit */
    public function update(StoreSpaceRequest $request, HelpDeskSpace $space, HelpDeskSpaceManager $spaces): JsonResponse
    {
        $helpDesk = $this->helpDesk();
        $this->guardAdminister($helpDesk);
        $this->guardSpace($helpDesk, $space);

        $spaces->update($helpDesk, $space, $this->user(), $this->attributes($request));

        return $this->listResponse($helpDesk, $space);
    }

    /**
     * POST /help-desk/spaces/{space}/inboxes — §6, §7, §12
     *
     * Assigning is always a move (see HelpDeskSpaceManager), so the screen that calls this shows
     * §7's confirmation for any inbox that already belongs to another space.
     */
    public function assign(Request $request, HelpDeskSpace $space, HelpDeskSpaceManager $spaces): JsonResponse
    {
        $helpDesk = $this->helpDesk();
        $this->guardAdminister($helpDesk);
        $this->guardSpace($helpDesk, $space);

        $validated = $request->validate([
            'inbox_ids' => ['required', 'array'],
            'inbox_ids.*' => ['integer'],
        ]);

        $moved = $spaces->assign($helpDesk, $space, $validated['inbox_ids'], $this->user());

        return $this->listResponse($helpDesk, $space, ['moved' => $moved]);
    }

    /** DELETE /help-desk/spaces/{space}/inboxes/{inbox} — take it out of the space (§12) */
    public function unassign(HelpDeskSpace $space, HelpDeskInbox $inbox, HelpDeskSpaceManager $spaces): JsonResponse
    {
        $helpDesk = $this->helpDesk();
        $this->guardAdminister($helpDesk);
        $this->guardSpace($helpDesk, $space);
        abort_unless((int) $inbox->help_desk_space_id === (int) $space->id, 404);

        $spaces->unassign($helpDesk, $inbox, $this->user());

        return $this->listResponse($helpDesk, $space);
    }

    /** POST /help-desk/spaces/{space}/archive — §8 */
    public function archive(HelpDeskSpace $space, HelpDeskSpaceManager $spaces, HelpDeskSpaceContext $context): JsonResponse
    {
        $helpDesk = $this->helpDesk();
        $this->guardAdminister($helpDesk);
        $this->guardSpace($helpDesk, $space);

        $spaces->archive($helpDesk, $space, $this->user());

        // Nobody should be left standing in an archived space.
        if ($context->current($this->user(), $this->workspace()) === $space->id) {
            $context->forget($this->workspace());
        }

        return response()->json([
            'ok' => true,
            'spaces' => $this->spaceRows($helpDesk, false),
            'redirect' => route('help-desk.spaces'),
        ]);
    }

    /** POST /help-desk/spaces/{space}/restore — the other half of §8's Archive */
    public function restore(HelpDeskSpace $space, HelpDeskSpaceManager $spaces): JsonResponse
    {
        $helpDesk = $this->helpDesk();
        $this->guardAdminister($helpDesk);
        $this->guardSpace($helpDesk, $space);

        $spaces->restore($helpDesk, $space, $this->user());

        return response()->json(['ok' => true, 'spaces' => $this->spaceRows($helpDesk, true)]);
    }

    /**
     * POST /help-desk/spaces/switch — §13's switcher.
     *
     * A POST and a redirect rather than a link, because it changes what every subsequent screen
     * shows. `space` empty means "across all of them", which is where somebody who has not
     * chosen starts.
     */
    public function switch(Request $request, HelpDeskSpaceContext $context): RedirectResponse
    {
        $this->helpDesk();

        $spaceId = (int) $request->integer('space');

        if (! $context->set($this->user(), $this->workspace(), $spaceId ?: null)) {
            // Not a space they may open. Refused rather than ignored: silently leaving them
            // where they were would look like the switcher is broken.
            abort(403);
        }

        return redirect()->to($this->safeReturnUrl($request));
    }

    // ---- payloads ---------------------------------------------------------------------------

    /** The six colours the avatar picker offers. Fixed, so three spaces never look alike. */
    private const COLORS = ['#2563EB', '#7C3AED', '#059669', '#D97706', '#DC2626', '#0891B2'];

    /** @return array<string, mixed> */
    private function attributes(StoreSpaceRequest $request): array
    {
        $types = HelpDeskSpace::cleanTypes((array) ($request->validated('types') ?? []));

        return [
            'name' => (string) $request->validated('name'),
            'description' => $request->validated('description'),
            // Null rather than [] for "none given": one way of saying a thing, not two.
            'types' => $types === [] ? null : $types,
            'color' => $request->validated('color'),
        ];
    }

    /**
     * The space list as §8's table reads it: inboxes, members and open conversations.
     *
     * All three counts come from the same pass. Members are counted through the inboxes rather
     * than from a membership row of their own — a space has no members of its own (H38), so the
     * honest number is "people who can open at least one inbox in here", and Admins and Managers
     * are added because they reach every inbox by role.
     *
     * @return array<int, array<string, mixed>>
     */
    private function spaceRows(HelpDesk $helpDesk, bool $includeArchived): array
    {
        $reachEverything = $this->membersReachingEveryInbox($helpDesk);

        return HelpDeskSpace::query()
            ->where('help_desk_id', $helpDesk->id)
            ->when(! $includeArchived, fn ($q) => $q->whereNull('archived_at'))
            ->withCount('inboxes')
            ->orderBy('name')
            ->get()
            ->map(fn (HelpDeskSpace $space) => $this->spaceRow($space, $helpDesk, $reachEverything))
            ->all();
    }

    /** @return array<string, mixed> */
    private function spaceRow(HelpDeskSpace $space, HelpDesk $helpDesk, ?int $reachEverything = null): array
    {
        $inboxIds = HelpDeskInbox::query()->where('help_desk_space_id', $space->id)->pluck('id')->all();

        return [
            'id' => $space->id,
            'name' => $space->name,
            'description' => $space->description,
            'types' => $space->typeLabels(),
            'color' => $space->color,
            'initial' => $space->initial(),
            'archived' => $space->isArchived(),
            // The Setup Inbox flow's list behaviour: an unfinished space offers "Continue to
            // Setup Inbox" and a finished one offers its inbox instead.
            'setup_complete' => $space->isSetUp(),
            'setup_step' => $space->setupStep(),
            'setup_url' => route('help-desk.spaces.setup', $space),
            'inbox_url' => $space->setup_inbox_id
                ? route('help-desk.inboxes.addresses', $space->setup_inbox_id)
                : null,
            'inbox_name' => $space->setupInbox?->name,
            'inbox_status' => $this->inboxStatus($space),
            'inboxes_count' => $space->inboxes_count ?? count($inboxIds),
            'members_count' => $this->memberReach($helpDesk, $inboxIds, $reachEverything),
            'open_conversations' => $inboxIds === [] ? 0 : HelpDeskConversation::query()
                ->whereIn('help_desk_inbox_id', $inboxIds)
                ->where('status', HelpDeskConversation::STATUS_OPEN)
                ->count(),
            'url' => route('help-desk.spaces.show', $space),
        ];
    }

    /**
     * What the setup column says about a space.
     *
     * Read from the addresses rather than from the wizard's step counter: finishing the flow
     * means somebody walked through it, and CONNECTED means mail has actually arrived. The two
     * are different facts and the list shows the second, because that is the one an
     * administrator is checking for.
     */
    private function inboxStatus(HelpDeskSpace $space): string
    {
        if (! $space->setup_inbox_id) {
            return 'Setup required';
        }

        $addresses = HelpDeskEmailAddress::query()
            ->where('help_desk_inbox_id', $space->setup_inbox_id)
            ->get();

        if ($addresses->isEmpty()) {
            return 'Setup required';
        }

        if ($addresses->contains(fn (HelpDeskEmailAddress $a) => $a->isConnected())) {
            return 'Connected';
        }

        return $space->isSetUp() ? 'Waiting for email' : 'Setup required';
    }

    /**
     * How many people can work in a space (§8's Members column).
     *
     * @param  array<int, int>  $inboxIds
     */
    private function memberReach(HelpDesk $helpDesk, array $inboxIds, ?int $reachEverything): int
    {
        $reachEverything ??= $this->membersReachingEveryInbox($helpDesk);

        if ($inboxIds === []) {
            return $reachEverything;
        }

        $named = HelpDeskMember::query()
            ->where('help_desk_id', $helpDesk->id)
            ->active()
            ->whereHas('inboxes', fn ($q) => $q->whereIn('help_desk_inboxes.id', $inboxIds))
            ->count();

        return $reachEverything + $named;
    }

    /** Active members whose ROLE reaches every inbox — Admins and Managers (FR-1.7). */
    private function membersReachingEveryInbox(HelpDesk $helpDesk): int
    {
        $roles = array_keys(array_filter(
            (array) config('help-desk.roles'),
            fn ($role) => (bool) ($role['all_inboxes'] ?? false),
        ));

        return HelpDeskMember::query()
            ->where('help_desk_id', $helpDesk->id)
            ->active()
            ->whereIn('role', $roles)
            ->count();
    }

    /**
     * The inboxes inside a space (§10).
     *
     * @return array<int, array<string, mixed>>
     */
    private function inboxRowsFor(HelpDeskSpace $space): array
    {
        return HelpDeskInbox::query()
            ->where('help_desk_space_id', $space->id)
            ->withCount('conversations')
            ->orderBy('name')
            ->get()
            ->map(fn (HelpDeskInbox $inbox) => [
                'id' => $inbox->id,
                'name' => $inbox->name,
                'inbound_address' => $inbox->inbound_address,
                'conversations_count' => $inbox->conversations_count,
                'addresses_url' => route('help-desk.inboxes.addresses', $inbox),
                'unassign_url' => route('help-desk.spaces.unassign', ['space' => $space->id, 'inbox' => $inbox->id]),
            ])
            ->all();
    }

    /**
     * Every inbox this Help Desk has, with where it currently lives (§6's selector).
     *
     * Inboxes already in `$exclude` are left out — offering to move an inbox to the space it is
     * already in is an action that does nothing.
     *
     * @return array<int, array<string, mixed>>
     */
    private function assignableInboxes(HelpDesk $helpDesk, ?HelpDeskSpace $exclude = null): array
    {
        return HelpDeskInbox::query()
            ->where('help_desk_id', $helpDesk->id)
            ->when($exclude, fn ($q) => $q->where(fn ($w) => $w
                ->whereNull('help_desk_space_id')
                ->orWhere('help_desk_space_id', '!=', $exclude->id)))
            ->with('space')
            ->orderBy('name')
            ->get()
            ->map(fn (HelpDeskInbox $inbox) => [
                'id' => $inbox->id,
                'name' => $inbox->name,
                'inbound_address' => $inbox->inbound_address,
                // What §6 calls "current assignment", and what turns an assign into a move.
                'space_id' => $inbox->help_desk_space_id,
                'space' => $inbox->space?->name,
            ])
            ->all();
    }

    /**
     * Inboxes in no space at all (§12).
     *
     * Surfaced on the list screen rather than left to be discovered: an unassigned inbox still
     * receives mail, and mail arriving somewhere nobody is looking is the failure this section
     * exists to prevent.
     *
     * @return array<int, array<string, mixed>>
     */
    private function unassignedInboxes(HelpDesk $helpDesk): array
    {
        return HelpDeskInbox::query()
            ->where('help_desk_id', $helpDesk->id)
            ->whereNull('help_desk_space_id')
            ->orderBy('name')
            ->get()
            ->map(fn (HelpDeskInbox $inbox) => [
                'id' => $inbox->id,
                'name' => $inbox->name,
                'inbound_address' => $inbox->inbound_address,
            ])
            ->all();
    }

    /**
     * The types the forms OFFER as one-press chips (§5's six examples).
     *
     * Suggestions, not a list to choose from: the field takes free text, and these exist so the
     * common cases stay spelled the same way across spaces rather than to limit anybody.
     *
     * @return array<int, string>
     */
    private function typeSuggestions(): array
    {
        return HelpDeskSpace::TYPE_SUGGESTIONS;
    }

    /** @param array<string, mixed> $extra */
    private function listResponse(HelpDesk $helpDesk, HelpDeskSpace $space, array $extra = []): JsonResponse
    {
        $space = $space->fresh();

        return response()->json(array_merge([
            'ok' => true,
            'space' => $this->spaceRow($space, $helpDesk),
            'inboxes' => $this->inboxRowsFor($space),
            'assignable' => $this->assignableInboxes($helpDesk, $space),
            'spaces' => $this->spaceRows($helpDesk, false),
        ], $extra));
    }

    private function guardSpace(HelpDesk $helpDesk, HelpDeskSpace $space): void
    {
        abort_unless((int) $space->help_desk_id === (int) $helpDesk->id, 404);
    }

    /**
     * Where to go back to after switching.
     *
     * Only ever a path on this host: the value comes from the request, and redirecting to
     * whatever it says is how a switcher becomes an open redirect.
     */
    private function safeReturnUrl(Request $request): string
    {
        $return = (string) $request->input('return', '');
        $path = parse_url($return, PHP_URL_PATH) ?: '';

        return str_starts_with($path, '/help-desk')
            ? $path.(($q = parse_url($return, PHP_URL_QUERY)) ? '?'.$q : '')
            : route('help-desk.index');
    }
}
