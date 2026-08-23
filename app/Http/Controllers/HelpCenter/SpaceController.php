<?php

namespace App\Http\Controllers\HelpCenter;

use App\Http\Controllers\Controller;
use App\Http\Controllers\HelpCenter\Concerns\GuardsHelpCenter;
use App\Http\Requests\HelpCenter\StoreSpaceRequest;
use App\Http\Requests\HelpCenter\UpdateSpaceRequest;
use App\Models\HelpCenterInboundTest;
use App\Models\HelpCenterRequest;
use App\Models\HelpCenterSpace;
use App\Models\User;
use App\Services\HelpCenter\EligibleLeads;
use App\Services\HelpCenter\HelpCenterNavigation;
use App\Services\HelpCenter\Reporting\ReportFilters;
use App\Services\HelpCenter\Reporting\SpaceReport;
use App\Services\HelpCenter\HelpCenterSpaceManager;
use App\Services\HelpCenter\RequestQueue;
use App\Services\HelpCenter\RequestViews;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Spaces after onboarding (docs/features/help-center.md §4, §15, §16).
 *
 * "After initial onboarding, authorized users can create additional Spaces using Spaces + …
 * Creating additional Spaces should not trigger the complete first-time Help Desk onboarding"
 * (§4). That second sentence needs no code here: onboarding is complete once any Inbox is set
 * up (HC-D3), and creating a Space cannot un-complete it.
 */
class SpaceController extends Controller
{
    use GuardsHelpCenter;

    public function __construct(
        private readonly HelpCenterSpaceManager $spaces,
        private readonly RequestQueue $queue,
    ) {}

    /**
     * GET /help-center/spaces — the Spaces listing (P3 §20).
     *
     * The management screen for the module, in the shape of the Projects index: a header, one
     * primary action, and a card per Space. It replaces "Inboxes" in the navigation, because a
     * Space is the thing you manage and its Inbox is one of the things a Space HAS.
     */
    public function index(HelpCenterNavigation $nav): View|RedirectResponse
    {
        $workspace = $this->helpCenterWorkspace();
        $user = Auth::user();

        return view('help-center.spaces', [
            'workspace' => $workspace,
            'section' => 'spaces',
            'bootstrap' => [
                'spaces' => $this->cards($user),
                'canCreate' => $user->can('create', HelpCenterSpace::class),
                'urls' => ['setup' => route('help-center.setup')],
                'endpointTemplates' => [
                    'show' => route('help-center.spaces.show', ['space' => '__ID__']),
                    'archive' => route('help-center.spaces.archive', ['space' => '__ID__']),
                    'destroy' => route('help-center.spaces.destroy', ['space' => '__ID__']),
                ],
            ],
        ]);
    }

    /**
     * PATCH /help-center/spaces/{space} — edit an existing Space (P3 §5).
     *
     * An UPDATE against this Space's id and nothing else. P3 §26's rule cuts both ways: creating
     * must never update an existing Space, and editing must never touch another one — which is
     * why the id comes from the route rather than from the payload.
     */
    public function update(UpdateSpaceRequest $request, HelpCenterSpace $space): JsonResponse
    {
        $this->helpCenterWorkspace();
        abort_unless(Auth::user()->can('update', $space), 403);

        $data = $request->validated();

        $space->forceFill([
            'name' => $data['name'],
            'description' => ($data['description'] ?? '') === '' ? null : $data['description'],
            'types' => $data['types'],
            'department_groups' => $data['department_groups'],
            'lead_user_id' => $data['lead_user_id'],
        ]);

        /*
         * Written ONLY when the form sent it (P65).
         *
         * The edit drawer does not render the sender name — Settings → Inbox does — and a Space
         * saved from the drawer must not clear a display name it never showed. Blank means "use
         * the Space name", stored as NULL so `senderName()` keeps falling back rather than
         * sending mail from an empty quoted string.
         */
        if (array_key_exists('inbound_display_name', $data)) {
            $space->inbound_display_name = ($data['inbound_display_name'] ?? '') === ''
                ? null
                : $data['inbound_display_name'];
        }

        $space->save();

        return response()->json([
            'ok' => true,
            'space' => $space->fresh()->load('lead')->toPayload(),
            'message' => 'Space updated.',
        ]);
    }

    /**
     * PATCH /help-center/spaces/{space}/archive — and its undo.
     *
     * Archiving is reversible and touches nothing else: the Inbox, the workflow, the members and
     * the settings all stay exactly where they are (P3 §6). It is a visibility switch, which is
     * why it is separate from destroy().
     */
    public function archive(Request $request, HelpCenterSpace $space): JsonResponse
    {
        $this->helpCenterWorkspace();
        abort_unless(Auth::user()->can('archive', $space), 403);

        $archive = $request->boolean('archived', true);

        $space->forceFill(['archived_at' => $archive ? now() : null])->save();

        return response()->json([
            'ok' => true,
            'space' => $this->card($space->fresh()->load('lead')),
            'message' => $archive ? 'Space archived.' : 'Space restored.',
        ]);
    }

    /**
     * DELETE /help-center/spaces/{space}
     *
     * Deletes the Space and, by the foreign keys, everything that belongs to it — Inbox, email
     * addresses, members, workflow statuses, settings. Nothing outside it is touched (P3 §2),
     * which is the point of every one of those rows carrying `help_center_space_id`.
     *
     * Irreversible, so the UI confirms and this checks the typed name matches.
     */
    public function destroy(Request $request, HelpCenterSpace $space): JsonResponse
    {
        $this->helpCenterWorkspace();
        abort_unless(Auth::user()->can('delete', $space), 403);

        /*
         * The typed name has to match.
         *
         * Deleting a Space takes its conversations, its workflow and its inbound address with
         * it, and an inbound address cannot be got back — mail already forwarded to it stops
         * arriving. A confirm dialog is one careless click; typing the name is a decision.
         */
        if (mb_strtolower(trim((string) $request->input('confirm'))) !== mb_strtolower($space->name)) {
            return response()->json([
                'ok' => false,
                'message' => 'Type the Space name exactly to confirm.',
            ], 422);
        }

        $space->delete();

        return response()->json(['ok' => true, 'message' => 'Space deleted.']);
    }

    /**
     * What the Edit Space dialog boots with (P3 §5).
     *
     * Current values AND the pickers, so the dialog opens filled in and needs no second request
     * — the point of the screen is filling gaps, and a form that arrives empty while it loads
     * invites somebody to save blanks over what is already there.
     *
     * @return array<string, mixed>
     */
    private function editPayload(HelpCenterSpace $space): array
    {
        $workspace = Auth::user()->currentWorkspace;

        return [
            'can' => Auth::user()->can('update', $space),
            'space' => [
                'name' => $space->name,
                'description' => (string) $space->description,
                'types' => $space->typeList(),
                'department_groups' => $space->groupList(),
                'lead_user_id' => $space->lead_user_id ? (string) $space->lead_user_id : '',
            ],
            'typeSuggestions' => array_values((array) config('help-center.space_type_suggestions')),
            'typeMax' => (int) config('help-center.space_type_max', 8),
            'typeMaxLength' => (int) config('help-center.space_type_max_length', 40),
            'groupMax' => (int) config('help-center.department_group_max', 20),
            'leads' => app(EligibleLeads::class)->options($workspace),
            'endpoint' => route('help-center.spaces.update', $space),
        ];
    }

    /**
     * What the inbound test card boots with (P7).
     *
     * The latest attempt and the last PASSED one are both sent: a failing retry should not erase
     * "this was verified on Tuesday", which is the fact the user actually relies on.
     *
     * @return array<string, mixed>
     */
    private function inboundTestPayload(HelpCenterSpace $space): array
    {
        $inbox = $space->inboxes->first();

        $latest = HelpCenterInboundTest::query()
            ->where('help_center_space_id', $space->id)->latest('id')->first();

        $passed = HelpCenterInboundTest::query()
            ->where('help_center_space_id', $space->id)
            ->where('status', HelpCenterInboundTest::STATUS_PASSED)
            ->latest('id')->first();

        return [
            'test' => $latest?->toPayload(),
            'lastPassed' => $passed?->toPayload(),
            'inboundAddress' => $inbox?->inboundAddress(),
            'canRun' => Auth::user()->can('update', $space),
            'endpoints' => [
                'start' => route('help-center.spaces.inbound-test.store', $space),
                'status' => route('help-center.spaces.inbound-test.show', $space),
            ],
            'urls' => [
                'inbox' => route('help-center.spaces.section', ['space' => $space->id, 'section' => 'inbox']),
            ],
        ];
    }

    /**
     * The Inbox screen's Requests (docs/features/help-center.md, P9).
     *
     * The Inbox list IS the work item grid — same rows, same group headers — because an agent
     * moving between a project and a Space should not have to learn a second way to read a list.
     *
     * The STATUSES ARE THE SPACE'S OWN. Nothing here names a status, and nothing downstream
     * assumes one exists: a Space running `New / Investigating / Completed` and one running
     * `Open / Tier 1 / Tier 2 / Resolved` both work because the filters, the groups and the
     * status picker are all built from this list rather than from a constant (P9, Inbox Status
     * Filters).
     *
     * Every row is sent at once and filtered on the client. One Space's volume does not justify
     * paginating before a Request even has a detail screen to open, and re-fetching to move
     * between two status chips would be a round trip for a decision the page can already make.
     *
     * The ownership views — Unassigned, Mine, Draft, Assigned, Closed, Spam — are NOT here any
     * more (P21). They are the Help Center's top-level navigation, counted across every Space,
     * and `RequestViews` is the one place that says what each of them means. This screen is the
     * Space's own workflow and nothing else.
     *
     * @return array<string, mixed>
     */
    /**
     * GET /help-center/spaces/{space}/inbox/rows — the list, as JSON (P67).
     *
     * What a socket event sends the screen back for. The SAME query the page was rendered from,
     * deliberately: ordering ("longest waiting first"), the spam and snooze exclusions and the
     * URL's drill-down filters are all rules with one implementation, and a browser that
     * inserted a new row where it guessed it belonged would be a second implementation of them
     * — the first to drift.
     *
     * This is not polling. It is answered once per thing that actually happened, and the
     * requirement's objection is to a timer, not to a fetch.
     */
    public function rows(Request $request, HelpCenterSpace $space): JsonResponse
    {
        $this->helpCenterWorkspace();
        abort_unless(Auth::user()->can('view', $space), 404);

        return response()->json(['ok' => true, 'rows' => $this->inboxRows($space)]);
    }

    private function inboxPayload(HelpCenterSpace $space, ?HelpCenterRequest $pageRequest = null): array
    {
        $me = (int) Auth::id();

        $rows = $this->inboxRows($space, $pageRequest);

        return $this->inboxPayloadFor($space, $rows, $pageRequest, $me);
    }

    /**
     * The Inbox's rows (P9), extracted so the screen and its refresh cannot disagree (P67).
     *
     * @return array<int, array<string, mixed>>
     */
    private function inboxRows(HelpCenterSpace $space, ?HelpCenterRequest $pageRequest = null): array
    {
        return HelpCenterRequest::query()
            ->with(['assignee', 'status', 'tags'])
            ->forSpace($space->id)
            /*
             * On a Request's own page, the queue is JUST that Request (P46).
             *
             * The screen is the same one, and in page mode the list is not rendered at all — so
             * fetching the Space's whole Inbox to display one ticket would be work nobody sees.
             * The one row is still needed: the drawer reads its subject, chips and identifier
             * from the list, exactly as it does when opened from the grid.
             */
            ->when($pageRequest !== null, fn ($q) => $q->whereKey($pageRequest->id))
            /*
             * Neither SPAM nor SNOOZED (P47).
             *
             * This screen shows a Space's Requests grouped by its workflow statuses, Closed
             * among them — so it is a fuller list than the queue views, and that is deliberate:
             * closed is where a Request ENDS in this workflow, and an end state belongs in the
             * picture of the workflow.
             *
             * Spam and snoozed are not end states, they are removals: one thrown away, one
             * parked until a date. Both have a view of their own in the navigation now, and a
             * ticket that appears both there and here has not been moved anywhere.
             *
             * The snooze half is a P45 defect, found while fixing the spam half. P45's
             * requirement said "remove the ticket from the normal active Inbox view", and it was
             * only honoured for the six queue views — `RequestViews::apply()` — while this
             * screen, which is the Inbox an agent actually opens, kept showing them.
             *
             * On a Request's own PAGE neither filter applies: `whereKey` above has already
             * narrowed this to one row, and a page that refuses to render the ticket its URL
             * names because that ticket is snoozed would be a dead link (P46).
             */
            ->when($pageRequest === null, fn ($q) => $q->where('is_spam', false)->notSnoozed())
            /*
             * The dashboard's drill-down filters, read off the URL (P50 §17).
             *
             * `/spaces/12/inbox?priority=high&assignee=24` is what a KPI card, a status slice or
             * a tag row links to, and the requirement asks those links to carry their filters.
             * Applied here rather than in the screen so the URL is the truth: a filtered list can
             * be bookmarked, shared and reloaded, which a client-side filter cannot.
             *
             * The vocabulary is deliberately the SAME as `ReportFilters` reads — `assignee=0` is
             * unassigned in both places — so a dashboard link and a hand-edited address behave
             * identically.
             */
            ->when($pageRequest === null, fn ($q) => $this->applyInboxFilters($q))
            // Longest-waiting first, then most recent: an Inbox is a queue, and the row that has
            // been owed an answer the longest is the one that should be read first.
            ->orderByRaw('waiting_since IS NULL, waiting_since ASC')
            ->orderByDesc('last_activity_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (HelpCenterRequest $r) => $r->toPayload())
            ->all();
    }

    /**
     * Everything else the Inbox screen boots with.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function inboxPayloadFor(
        HelpCenterSpace $space,
        array $rows,
        ?HelpCenterRequest $pageRequest,
        int $me,
    ): array {
        return [
            'rows' => $rows,
            /*
             * Which Space this screen is, and where to refetch its rows (P67).
             *
             * The screen already knows the Space through every templated endpoint, but only as
             * a substring of a URL. The live update needs it as a number — it is the channel
             * name — and digging an id back out of a route is the kind of thing that works
             * until somebody changes the route.
             */
            'spaceId' => (int) $space->id,
            'rowsUrl' => route('help-center.spaces.inbox.rows', ['space' => $space->id]),
            /* The Space's workflow, in workflow order — the groups, the filters and the status
               picker are all this one list. */
            'statuses' => $space->statuses->sortBy('position')->values()
                ->map(fn ($s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'color' => $s->color,
                    'waiting_on' => $s->waiting_on,
                    'is_default' => (bool) $s->is_default,
                ])->all(),
            'priorities' => (array) config('help-center.priorities'),
            // The Snooze dialog's conditions (P45) — the same list `RequestQueue` sends, because
            // the same dialog opens at both scopes and two vocabularies would be two products.
            'snoozeConditions' => collect((array) config('help-center.snooze_conditions'))
                ->map(fn (array $c, string $key) => [
                    'value' => $key,
                    'label' => $c['label'],
                    'help' => $c['help'] ?? null,
                ])->values()->all(),
            // The rich-text editor's licence, as every other host of it passes it (P41).
            'editorLicense' => (string) config('projects.jodit_license'),
            // The Space's tag vocabulary, for the row's Tag chip (P28).
            'tags' => $space->tags->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])->values()->all(),
            /*
             * Accepted members, then invited ones flagged `pending` (P29).
             *
             * The same shape `RequestQueue::members()` builds, because the same picker reads
             * both — a member who is offered here and greyed out there would be two answers to
             * one question. `displayName()` rather than `name`, for the reason the assignee chip
             * needed it: a user with an empty name column rendered as a bare initial.
             */
            'members' => $space->members
                ->map(fn ($m) => $m->user ? [
                    'id' => $m->user->id,
                    'name' => $m->user->displayName(),
                    'initial' => mb_strtoupper(mb_substr((string) $m->user->displayName(), 0, 1)),
                    'avatar_url' => $m->user->avatar_url ?? null,
                    'pending' => false,
                ] : [
                    'id' => null,
                    'name' => (string) $m->email,
                    'initial' => mb_strtoupper(mb_substr((string) $m->email, 0, 1)) ?: '?',
                    'avatar_url' => null,
                    'pending' => true,
                ])
                ->sortBy('pending')->values()->all(),
            // Who "Mine" means, sent explicitly rather than read off some global — the client
            // has to re-tag a row it just changed, and it must use the same person the server did.
            'me' => $me,
            // The empty state answers "where do I send mail to see something here?".
            'inboundAddress' => $space->inboxes->first()?->inboundAddress(),
            'canManage' => Auth::user()->can('update', $space),
            'endpoints' => [
                'update' => route('help-center.spaces.requests.update', ['space' => $space->id, 'request' => '__ID__']),
                // The drawer's contents, fetched on open (P32).
                'detail' => route('help-center.spaces.requests.detail', ['space' => $space->id, 'request' => '__ID__']),
                // Snooze and Unsnooze — one URL, two verbs (P45).
                'snooze' => route('help-center.spaces.requests.snooze.store', ['space' => $space->id, 'request' => '__ID__']),
                // The Request's own page (P46) — Expand links here, Copy Ticket Link copies it.
                'page' => route('help-center.spaces.requests.page', ['space' => $space->id, 'request' => '__ID__']),
                // Where that page's breadcrumb goes back to. Not templated: a Space's Inbox is
                // the one queue every row on this screen belongs to.
                'queue' => route('help-center.spaces.section', ['space' => $space->id, 'section' => 'inbox']),
            ],
        ];
    }

    /**
     * Every Space in the workspace, as the listing needs it.
     *
     * @return array<int, array<string, mixed>>
     */
    private function cards(User $user): array
    {
        return HelpCenterSpace::query()
            ->with('lead')
            ->withCount(['members', 'inboxes'])
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn (HelpCenterSpace $space) => $this->card($space, $user))
            ->all();
    }

    /** @return array<string, mixed> */
    private function card(HelpCenterSpace $space, ?User $user = null): array
    {
        $user ??= Auth::user();

        return $space->toPayload() + [
            'members_count' => $space->members_count ?? $space->members()->count(),
            'inboxes_count' => $space->inboxes_count ?? $space->inboxes()->count(),
            /*
             * Conversations do not exist yet (HC-D8). NULL rather than 0, so the card can say
             * nothing at all instead of claiming a Space has none — which would be a
             * measurement, and a wrong one the day conversations arrive.
             */
            'conversations_count' => null,
            'archived' => $space->archived_at !== null,
            'status_label' => $space->archived_at !== null ? 'Archived' : 'Active',
            'updated_at' => $space->updated_at?->diffForHumans(),
            'manageable' => $space->manageableBy($user),
            'deletable' => $user->can('delete', $space),
        ];
    }

    /** POST /help-center/spaces — the "+" beside Spaces (§4, §15). */
    public function store(StoreSpaceRequest $request): JsonResponse
    {
        $this->helpCenterWorkspace();
        abort_unless(Auth::user()->can('create', HelpCenterSpace::class), 403);

        $space = $this->spaces->create(Auth::user(), $request->validated());

        return response()->json([
            'ok' => true,
            'space' => $space->load('lead')->toPayload(),
            'url' => route('help-center.spaces.show', $space),
            'message' => 'Space created.',
        ]);
    }

    /**
     * GET /help-center/spaces/{space}
     *
     * A Space opens on its first section (P4). Redirecting rather than rendering keeps one URL
     * per thing there is to look at, and makes the section visible in the address bar — which
     * is what survives a refresh and a shared link.
     */
    public function show(HelpCenterSpace $space): RedirectResponse
    {
        $this->helpCenterWorkspace();
        abort_unless(Auth::user()->can('view', $space), 404);

        return redirect()->route('help-center.spaces.section', [
            'space' => $space->id,
            'section' => HelpCenterNavigation::firstSection(),
        ]);
    }

    /**
     * GET /help-center/spaces/{space}/{section} — one part of a Space (P4).
     *
     * Everything shown is scoped to THIS Space: its inbox, its workflow, its members, its
     * settings. That is P3 §15's rule — a Space's screen must never be able to render another
     * Space's rows — and it is why each relation is loaded through `$space` rather than
     * queried by its own table.
     */
    /**
     * GET /help-center/spaces/{space}/requests/{request} — one Request, as a full page (P46).
     *
     * The requirement is that Expand behaves the way the work item's does, and the work item's
     * Expand is a LINK to the item's own page (`work-items.show`) rather than a wider panel.
     * This is that page.
     *
     * It renders the SAME Vue screen in page mode — so the detail here is not a second, thinner
     * copy of the drawer that would drift from it; it IS the drawer, with every picker, tab,
     * composer and dialog working, unwrapped to fill the page.
     *
     * Here rather than on `RequestController` because the bootstrap this needs is the Space
     * Inbox's, built a few methods up. The screen is a Space's Inbox focused on one row.
     */
    public function request(HelpCenterSpace $space, int $request, HelpCenterNavigation $nav): View
    {
        $workspace = $this->helpCenterWorkspace();
        abort_unless(Auth::user()->can('view', $space), 404);

        /*
         * Found THROUGH the Space, so a Request from another Space cannot be reached by putting
         * its id in this Space's URL — the same rule every other per-Request route follows.
         */
        $model = HelpCenterRequest::query()->forSpace($space->id)->findOrFail($request);

        $space->load(['lead', 'inboxes.emailAddresses', 'statuses', 'members.user', 'settings']);

        return view('help-center.request', [
            'workspace' => $workspace,
            'section' => 'spaces',
            'space' => $space,
            'request' => $model,
            // The Space's own nav, with Inbox lit: this page belongs to that queue, and a
            // sidebar showing nothing selected reads as having navigated out of the module.
            'sections' => $nav->views($space, request()),
            'inboxQueue' => $this->inboxPayload($space, $model) + ['pageRequestId' => $model->id],
        ]);
    }

    /**
     * GET /help-center/spaces/{space}/overview/data — the dashboard, as JSON (P50).
     *
     * The requirement asks that changing a filter refresh the reports "without requiring a full
     * page reload". This is that endpoint, and it returns exactly what the page was rendered with
     * — same service, same filters object — so the screen after a filter change and the screen
     * after a reload of the same URL cannot disagree.
     *
     * Gated on VIEWING the Space, like the Overview page it belongs to.
     */
    public function overviewData(Request $request, HelpCenterSpace $space): JsonResponse
    {
        $this->helpCenterWorkspace();
        abort_unless(Auth::user()->can('view', $space), 404);

        $space->load(['statuses', 'members.user']);

        return response()->json([
            'ok' => true,
            'report' => (new SpaceReport($space))->build(ReportFilters::fromRequest($request)),
        ]);
    }

    /**
     * What a Space is set up as (P51) — the rows the Space Configuration dialog shows.
     *
     * An em dash for anything unset rather than an empty cell: a blank next to "Space Lead" reads
     * as a rendering fault, where "—" reads as "nobody yet", which is the truth.
     *
     * Public and static because TWO screens render it now: the Overview's dialog (P51) and the
     * Inbox settings panel (P52). One builder, so the same Space cannot be described two ways.
     *
     * @return array<int, array{label: string, value: string, break?: bool}>
     */
    public static function configurationRows(HelpCenterSpace $space): array
    {
        $inbox = $space->inboxes->first();
        $dash = fn (?string $v) => ($v !== null && trim($v) !== '') ? $v : '—';

        return [
            ['label' => 'Space Lead', 'value' => $dash($space->lead?->displayName())],
            ['label' => 'Space Type', 'value' => $dash($space->typeLabel())],
            ['label' => 'Description', 'value' => $dash($space->description)],
            ['label' => 'Department Groups', 'value' => $dash(implode(', ', $space->groupList() ?: []))],
            ['label' => 'Support members', 'value' => (string) $space->members->count()],
            ['label' => 'Inbox', 'value' => $dash($inbox?->name)],
            // `break` marks a value that has to wrap mid-word — an inbound address is one long
            // token and would otherwise push the dialog wider than the viewport.
            ['label' => 'Inbound address', 'value' => $dash($inbox?->inboundAddress()), 'break' => true],
            // What a customer actually sees in their inbox (P65) — the resolved name, not the
            // raw column, because "—" here would be wrong: an unset display name still sends
            // under the Space's own name.
            ['label' => 'Sender name', 'value' => $space->senderName()],
            ['label' => 'Workflow', 'value' => $dash($space->statuses->pluck('name')->implode(' → '))],
        ];
    }

    /**
     * Narrow a Space's Inbox by the filters in the query string (P50 §17).
     *
     * Silently ignores anything unreadable. A stale link from a dashboard somebody left open
     * last week should show a wider list, not a 422 — the filters are a convenience, and the
     * screen shows what it actually ran.
     */
    private function applyInboxFilters($query)
    {
        $request = request();

        $ids = fn (string $key) => array_values(array_filter(
            array_map('intval', (array) $request->query($key, [])),
            fn ($v) => is_numeric($v),
        ));

        $assignees = $request->query('assignee') === null ? [] : $ids('assignee');

        if ($assignees !== []) {
            $real = array_values(array_filter($assignees, fn (int $id) => $id > 0));
            $wantsUnassigned = in_array(0, $assignees, true);

            $query->where(function ($q) use ($real, $wantsUnassigned) {
                if ($real !== []) {
                    $q->whereIn('assignee_id', $real);
                }

                if ($wantsUnassigned) {
                    $q->orWhereNull('assignee_id');
                }
            });
        }

        if ($request->query('status') !== null && ($statuses = $ids('status')) !== []) {
            $query->whereIn('help_center_status_id', $statuses);
        }

        if ($request->query('priority') !== null) {
            $valid = array_keys((array) config('help-center.priorities'));
            $priorities = array_values(array_intersect((array) $request->query('priority'), $valid));

            if ($priorities !== []) {
                $query->whereIn('priority', $priorities);
            }
        }

        if ($request->query('tag') !== null && ($tags = $ids('tag')) !== []) {
            $query->whereExists(function ($q) use ($tags) {
                $q->select(DB::raw(1))
                    ->from('help_center_request_tag')
                    ->whereColumn('help_center_request_tag.help_center_request_id', 'help_center_requests.id')
                    ->whereIn('help_center_request_tag.help_center_tag_id', $tags);
            });
        }

        return $query;
    }

    /**
     * The dashboard's filter vocabulary and its drill-down URLs (P50).
     *
     * @return array<string, mixed>
     */
    private function reportOptions(HelpCenterSpace $space): array
    {
        return [
            'ranges' => collect(ReportFilters::RANGES)
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values()->all(),
            'groupings' => ReportFilters::GROUPINGS,
            'statuses' => $space->statuses->sortBy('position')->values()
                ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'color' => $s->color])->all(),
            'priorities' => collect((array) config('help-center.priorities'))
                ->map(fn (array $m, string $k) => ['value' => $k, 'label' => $m['label'] ?? $k])
                ->values()->all(),
            'tags' => $space->tags->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])->values()->all(),
            /*
             * Accepted members plus an "Unassigned" entry carrying the id 0.
             *
             * The sentinel is the same one `ReportFilters` reads, because a null cannot survive a
             * query string and "unassigned" has to be selectable alongside real people.
             */
            'assignees' => $space->members->filter(fn ($m) => $m->user !== null)
                ->map(fn ($m) => [
                    'id' => $m->user->id,
                    'name' => $m->user->displayName(),
                    'initial' => mb_strtoupper(mb_substr((string) $m->user->displayName(), 0, 1)),
                    'avatar_url' => $m->user->avatar_url ?? null,
                ])->values()
                ->prepend(['id' => 0, 'name' => 'Unassigned', 'initial' => '?', 'avatar_url' => null])
                ->all(),
            /*
             * The Space's configuration, as label/value pairs (P51).
             *
             * Built here rather than read out of the model in the template, because it moved from
             * a table on the page into a dialog inside the Vue screen — and a dialog that had to
             * know about `groupList()`, `typeLabel()` and the Inbox relation would be a second
             * place that knows how a Space describes itself.
             *
             * Pairs rather than a fixed shape: the dialog renders whatever it is given, so adding
             * a row here is the whole change.
             */
            'configuration' => self::configurationRows($space),
            'endpoints' => [
                'data' => route('help-center.spaces.overview.data', ['space' => $space->id]),
                // Every drill-down lands on the Space's Inbox with the filters in the URL.
                'inbox' => route('help-center.spaces.section', ['space' => $space->id, 'section' => 'inbox']),
                'unassigned' => route('help-center.spaces.section', ['space' => $space->id, 'section' => 'unassigned']),
                'closed' => route('help-center.spaces.section', ['space' => $space->id, 'section' => 'closed']),
                'request' => route('help-center.spaces.requests.page', ['space' => $space->id, 'request' => '__ID__']),
            ],
        ];
    }

    public function section(Request $request, HelpCenterSpace $space, string $section, HelpCenterNavigation $nav): View
    {
        $workspace = $this->helpCenterWorkspace();
        abort_unless(Auth::user()->can('view', $space), 404);

        $config = (array) config('help-center.space_sections');

        $space->load(['lead', 'inboxes.emailAddresses', 'statuses', 'members.user', 'settings']);

        /*
         * A queue view of THIS Space (P22) — Unassigned, Mine, Draft, Assigned, Closed, Spam.
         *
         * The same screen the Help Center's own Inbox renders, built by the same service and
         * narrowed to this Space, so "Mine" means the same thing at both scopes and there is one
         * definition of what a row looks like.
         */
        $isView = RequestViews::item($section) !== null;

        return view('help-center.space', [
            'workspace' => $workspace,
            'section' => 'spaces',
            'space' => $space,
            'panel' => $section,
            'panelLabel' => $isView ? RequestViews::label($section) : $config[$section]['label'],
            'sections' => $nav->views($space, $request),
            /* The Inbox screen's grid (P9), and the queue views that share it (P22). Built only
               for the panels that render it.

               `$inboxQueue`, not `$inbox`: the Overview panel already binds `$inbox` to the
               Space's HelpCenterInbox model, and two different things under one name in one
               template is how a panel ends up rendering the other one's data. */
            'inboxQueue' => match (true) {
                $section === 'inbox' => $this->inboxPayload($space),
                $isView => $this->queue->payload($section, Auth::user(), $space),
                default => null,
            },
            /*
             * The Overview's reporting dashboard (P50).
             *
             * Built only for the Overview panel — every other section would pay four queries for
             * a payload it never renders.
             */
            'report' => $section === 'overview'
                ? (new SpaceReport($space))->build(ReportFilters::fromRequest($request))
                : null,
            'reportOptions' => $section === 'overview' ? $this->reportOptions($space) : null,
            // The Overview's inbound test card (P7).
            'inboundTest' => $this->inboundTestPayload($space),
            // The Overview's Edit Space dialog (P3 §5).
            'spaceEdit' => $this->editPayload($space),
        ]);
    }
}
