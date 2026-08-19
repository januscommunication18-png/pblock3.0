<?php

namespace App\Http\Controllers\HelpCenter;

use App\Http\Controllers\Controller;
use App\Http\Controllers\HelpCenter\Concerns\GuardsHelpCenter;
use App\Http\Requests\HelpCenter\StoreSpaceRequest;
use App\Http\Requests\HelpCenter\UpdateSpaceRequest;
use App\Models\HelpCenterInboundTest;
use App\Models\HelpCenterSpace;
use App\Models\User;
use App\Services\HelpCenter\EligibleLeads;
use App\Services\HelpCenter\HelpCenterNavigation;
use App\Services\HelpCenter\HelpCenterSpaceManager;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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

    public function __construct(private readonly HelpCenterSpaceManager $spaces) {}

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
        ])->save();

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
    public function section(Request $request, HelpCenterSpace $space, string $section, HelpCenterNavigation $nav): View
    {
        $workspace = $this->helpCenterWorkspace();
        abort_unless(Auth::user()->can('view', $space), 404);

        $config = (array) config('help-center.space_sections');

        $space->load(['lead', 'inboxes.emailAddresses', 'statuses', 'members.user', 'settings']);

        return view('help-center.space', [
            'workspace' => $workspace,
            'section' => 'spaces',
            'space' => $space,
            'panel' => $section,
            'panelLabel' => $config[$section]['label'],
            'sections' => $nav->views($space, $request),
            // The six conversation views are FILTERS here, not navigation (P4).
            'conversationViews' => (array) config('help-center.space_views'),
            // The Overview's inbound test card (P7).
            'inboundTest' => $this->inboundTestPayload($space),
            // The Overview's Edit Space dialog (P3 §5).
            'spaceEdit' => $this->editPayload($space),
        ]);
    }
}
