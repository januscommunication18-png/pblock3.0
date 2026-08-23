<?php

namespace App\Http\Controllers\HelpCenter;

use App\Http\Controllers\Controller;
use App\Http\Controllers\HelpCenter\Concerns\GuardsHelpCenter;
use App\Http\Requests\HelpCenter\Setup\InboxStepRequest;
use App\Http\Requests\HelpCenter\Setup\SettingsStepRequest;
use App\Http\Requests\HelpCenter\Setup\SpaceStepRequest;
use App\Http\Requests\HelpCenter\Setup\TeamStepRequest;
use App\Http\Requests\HelpCenter\Setup\WorkflowStepRequest;
use App\Models\HelpCenterSetupDraft;
use App\Models\HelpCenterSpace;
use App\Models\Workspace;
use App\Services\HelpCenter\EligibleLeads;
use App\Services\HelpCenter\EmailAddressGuard;
use App\Services\HelpCenter\SetupCommitter;
use App\Services\HelpCenter\SetupDraftStore;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The six-step Space onboarding wizard
 * (docs/features/help-center.md, P2 §2 and Steps 1–6).
 *
 * Every step endpoint does the same three things: check the gate, validate that step, and merge
 * it into the draft. **None of them writes a Space, an Inbox or anything else** — that is
 * HC-D11, and `store()` is the only method here that creates anything.
 *
 * Which step the wizard opens on comes from the draft's own `step`, not from the URL: a step in
 * the address bar is a second record of where somebody is, free to disagree with what they have
 * actually filled in.
 */
class SetupController extends Controller
{
    use GuardsHelpCenter;

    public function __construct(
        private readonly SetupDraftStore $drafts,
        private readonly SetupCommitter $committer,
        private readonly EligibleLeads $leads,
    ) {}

    /** GET /help-center/setup */
    public function show(): View|RedirectResponse
    {
        $workspace = $this->helpCenterWorkspace();
        $user = Auth::user();

        /*
         * Only somebody who may create a Space may run the wizard at all (§19).
         *
         * Rendered rather than refused, so an agent who follows the link is told who can finish
         * setup instead of meeting a bare 403 — but every WRITE below is gated too.
         */
        $draft = $user->can('create', HelpCenterSpace::class)
            ? $this->drafts->for($workspace, $user)
            : null;

        // A reload that resumes at Step 3 or later needs the address already in hand.
        if ($draft !== null && $draft->step >= 3) {
            $this->drafts->inboundId($draft);
        }

        return view('help-center.setup', [
            'workspace' => $workspace,
            // The Help Center nav highlights by section; the wizard is not one of its entries,
            // so nothing is marked active while you are in it.
            'section' => 'setup',
            'bootstrap' => [
                'step' => $draft?->step ?? 1,
                'totalSteps' => SetupDraftStore::TOTAL_STEPS,
                'steps' => $this->steps(),
                'draft' => $draft ? $this->drafts->payload($draft) : $this->drafts->blank(),
                'canCreate' => $draft !== null,

                'typeSuggestions' => array_values((array) config('help-center.space_type_suggestions')),
                'typeMax' => (int) config('help-center.space_type_max', 8),
                'typeMaxLength' => (int) config('help-center.space_type_max_length', 40),
                'groupMax' => (int) config('help-center.department_group_max', 20),

                'leads' => $this->leads->options($workspace),
                // Which roles THIS person may hand out (HC-D19) — the picker should not offer
                // an option the server will refuse.
                'roles' => $this->assignableRoles($workspace),

                // The wizard composes the inbound address for display; the token alone is
                // what the draft holds (HC-D5).
                'inboundDomain' => (string) config('help-center.inbound_domain'),
                'inboundPrefix' => (string) config('help-center.inbound_prefix', 'inbox'),
                'providers' => array_values((array) config('help-center.providers')),
                'statusColors' => array_values((array) config('help-center.status_colors')),
                'statusMax' => (int) config('help-center.status_max', 20),
                'responsibilities' => [
                    ['value' => 'creator', 'label' => 'Creator'],
                    ['value' => 'assignee', 'label' => 'Assignee'],
                ],

                'metadata' => $this->metadataOptions(),
                'destinations' => collect((array) config('help-center.reassign_destinations'))
                    ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                    ->values()->all(),

                'endpoints' => [
                    'space' => route('help-center.setup.space'),
                    'team' => route('help-center.setup.team'),
                    'inbox' => route('help-center.setup.inbox'),
                    'workflow' => route('help-center.setup.workflow'),
                    'settings' => route('help-center.setup.settings'),
                    'back' => route('help-center.setup.back'),
                    'store' => route('help-center.setup.store'),
                    'cancel' => route('help-center.setup.cancel'),
                    'checkAddress' => route('help-center.setup.check-address'),
                ],
                'urls' => [
                    'exit' => route('projects.index'),
                    'helpCenter' => route('help-center.index'),
                ],
            ],
        ]);
    }

    /** POST /help-center/setup/space — Step 1 (P2 §4–§6). */
    public function space(SpaceStepRequest $request): JsonResponse
    {
        return $this->step($request->validated(), SetupDraftStore::SPACE, 2);
    }

    /** POST /help-center/setup/team — Step 2 (P2 §7, §8). */
    public function team(TeamStepRequest $request): JsonResponse
    {
        return $this->step($request->validated(), SetupDraftStore::TEAM, 3);
    }

    /** POST /help-center/setup/inbox — Step 3 (P2 §9, §10). */
    public function inbox(InboxStepRequest $request): JsonResponse
    {
        $draft = $this->draft();

        // The identifier is reserved once and read back unchanged on every later visit
        // (HC-D12) — P2 §10's "do not regenerate when navigating back and forth".
        $values = $request->validated() + ['inbound_id' => $this->drafts->inboundId($draft)];

        return $this->step($values, SetupDraftStore::INBOX, 4);
    }

    /** POST /help-center/setup/workflow — Step 4 (P2 §11–§16). */
    public function workflow(WorkflowStepRequest $request): JsonResponse
    {
        return $this->step($request->validated(), SetupDraftStore::WORKFLOW, 5);
    }

    /** POST /help-center/setup/settings — Step 5 (P2 §17–§24). */
    public function settings(SettingsStepRequest $request): JsonResponse
    {
        return $this->step($request->validated(), SetupDraftStore::SETTINGS, 6);
    }

    /**
     * POST /help-center/setup/back — move to an earlier step.
     *
     * A write, not a read, because P2 §2 requires data to survive moving backward: the client
     * sends what is on screen and it is merged in before the step changes. There is no
     * validation — half-finished values are exactly what going Back is for, and refusing to
     * remember them would be the bug.
     *
     * This is also what §27's per-section **Edit** on the review screen calls.
     */
    public function back(Request $request): JsonResponse
    {
        $this->helpCenterWorkspace();
        $draft = $this->draft();

        $section = (string) $request->input('section');
        $values = $request->input('values');

        if (in_array($section, $this->sections(), true) && is_array($values)) {
            $draft->putSection($section, $values);
        }

        // `step` is the FURTHEST point reached, so it is not lowered here — going back to fix
        // step 1 must not forget that steps 2 and 3 are already done.
        $draft->save();

        return response()->json(['ok' => true, 'draft' => $this->drafts->payload($draft)]);
    }

    /**
     * POST /help-center/setup/check-address — the Add button's answer (§7).
     *
     * Unchanged from Phase 1: §7 wants "already connected to another Inbox" said when the
     * address is added, not when the step is submitted.
     */
    public function checkAddress(Request $request, EmailAddressGuard $guard): JsonResponse
    {
        $workspace = $this->helpCenterWorkspace();

        $email = mb_strtolower(trim((string) $request->input('email')));

        if (! EmailAddressGuard::isRoutable($email)) {
            return response()->json(['ok' => false, 'message' => 'Enter a valid email address.'], 422);
        }

        if ($guard->isTaken($workspace, $email)) {
            return response()->json(['ok' => false, 'message' => EmailAddressGuard::TAKEN_MESSAGE], 422);
        }

        return response()->json(['ok' => true, 'email' => $email]);
    }

    /**
     * POST /help-center/setup/store — Step 6's **Create Help Desk** (P2 §28).
     *
     * The only endpoint in this controller that creates anything.
     */
    public function store(): JsonResponse
    {
        $workspace = $this->helpCenterWorkspace();
        $user = Auth::user();
        abort_unless($user->can('create', HelpCenterSpace::class), 403);

        $draft = $this->draft();

        try {
            $inbox = $this->committer->commit($workspace, $user, $draft);
        } catch (Throwable $e) {
            /*
             * P2 §29: "If an API request fails during final creation, preserve onboarding data
             * and clearly identify the failed step/action. Do not silently discard
             * configuration after a failed submission."
             *
             * The transaction has already rolled back, so nothing was half-created — and the
             * draft is untouched, so the user can correct and try again rather than retype six
             * steps. Logged with the workspace so the failure is findable.
             */
            Log::error('help-center.setup.commit_failed', [
                'workspace_id' => $workspace->id,
                'user_id' => $user->id,
                'draft_id' => $draft->id,
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'ok' => false,
                'step' => 6,
                'message' => 'Your Help Desk could not be created. Nothing was saved and your setup has been kept — please try again.',
            ], 500);
        }

        return response()->json([
            'ok' => true,
            /*
             * Straight to the Space that was just created (P3 §9).
             *
             * Not the Inboxes LIST, which was the earlier target: with several Spaces that is a
             * page of everybody's inboxes, and "you are now in the Space you just made" is the
             * thing to say. `spaces.show` redirects on to its first system view, so the nav
             * opens with the new Space expanded and selected — which is also what makes it the
             * active Space on the next page load (P3 §12).
             */
            'redirect' => route('help-center.spaces.show', $inbox->help_center_space_id),
            'inbox' => $inbox->load('emailAddresses')->toPayload(),
        ]);
    }

    /**
     * POST /help-center/setup/cancel — Cancel Setup.
     *
     * Throws the draft away deliberately. Everything else in this flow preserves it, so the one
     * action that means "I do not want this" has to actually mean it.
     */
    public function cancel(): JsonResponse
    {
        $this->helpCenterWorkspace();

        $this->drafts->discard($this->draft());

        return response()->json(['ok' => true, 'redirect' => route('projects.index')]);
    }

    // ---- internals ---------------------------------------------------------------------------

    /** Validate-and-merge, which is all any step but the last one does. */
    /** @param  array<string, mixed>  $values */
    private function step(array $values, string $section, int $nextStep): JsonResponse
    {
        $this->helpCenterWorkspace();
        abort_unless(Auth::user()->can('create', HelpCenterSpace::class), 403);

        $draft = $this->drafts->save($this->draft(), $section, $values, $nextStep);

        /*
         * Reserve the inbound identifier as soon as the wizard is heading for Step 3.
         *
         * Step 3 DISPLAYS the address for the user to copy, so it has to exist before that step
         * is submitted rather than as a side effect of submitting it. Allocated once and read
         * back unchanged afterwards (HC-D12), which is what makes P2 §10's "do not regenerate
         * when navigating back and forth" true.
         */
        if ($nextStep >= 3) {
            $this->drafts->inboundId($draft);
        }

        return response()->json([
            'ok' => true,
            'step' => $nextStep,
            'draft' => $this->drafts->payload($draft),
        ]);
    }

    private function draft(): HelpCenterSetupDraft
    {
        return $this->drafts->for(Auth::user()->currentWorkspace, Auth::user());
    }

    /** @return array<int, string> */
    private function sections(): array
    {
        return [
            SetupDraftStore::SPACE,
            SetupDraftStore::TEAM,
            SetupDraftStore::INBOX,
            SetupDraftStore::WORKFLOW,
            SetupDraftStore::SETTINGS,
        ];
    }

    /**
     * The six steps, in order, with the section each one owns (P2 §2).
     *
     * One list, used by the progress indicator, the Back action and Review's Edit links — three
     * places that must agree about what step 4 is called and which data it holds.
     *
     * @return array<int, array<string, mixed>>
     */
    private function steps(): array
    {
        /*
         * `short` is what the progress bar DRAWS; `label` is what it means.
         *
         * The six full labels come to about 120 characters, which is more than a single row can
         * hold at any width this screen should be — so the bar wrapped onto two lines and the
         * numbers stopped reading as a sequence. Truncating them would have been worse: half a
         * label is a word nobody can act on. These are the same six steps named in one word
         * each, with the full label kept as the row's `title` and as the heading of the step
         * you are actually standing on.
         */
        return [
            ['number' => 1, 'label' => 'Create Your Space', 'short' => 'Space', 'section' => SetupDraftStore::SPACE],
            ['number' => 2, 'label' => 'Invite Your Support Group', 'short' => 'Team', 'section' => SetupDraftStore::TEAM],
            ['number' => 3, 'label' => 'Set Up Your Inbox', 'short' => 'Inbox', 'section' => SetupDraftStore::INBOX],
            ['number' => 4, 'label' => 'Configure Your Workflow', 'short' => 'Workflow', 'section' => SetupDraftStore::WORKFLOW],
            ['number' => 5, 'label' => 'Conversation Settings', 'short' => 'Settings', 'section' => SetupDraftStore::SETTINGS],
            ['number' => 6, 'label' => 'Review & Confirm', 'short' => 'Review', 'section' => null],
        ];
    }

    /**
     * The roles this person may hand out (HC-D19).
     *
     * Filtered by the same policy the server enforces, so the picker never offers an option the
     * submit will refuse — an admin does not see "Admin" in the list.
     *
     * @return array<int, array<string, string>>
     */
    private function assignableRoles(Workspace $workspace): array
    {
        $labels = (array) config('workspace.roles');
        $user = Auth::user();

        return collect((array) config('workspace.invite_roles'))
            ->filter(fn (string $role) => $user->can('assignRole', [$workspace, $role]))
            ->map(fn (string $role) => ['value' => $role, 'label' => $labels[$role] ?? ucfirst($role)])
            ->values()
            ->all();
    }

    /**
     * Step 5's toggles, with what each one is and whether it can be switched on (P2 §18).
     *
     * @return array<int, array<string, mixed>>
     */
    private function metadataOptions(): array
    {
        return collect((array) config('help-center.metadata'))
            ->map(fn (array $meta, string $key) => [
                'key' => $key,
                'label' => $meta['label'],
                'help' => $meta['help'] ?? '',
                'available' => (bool) ($meta['available'] ?? false),
            ])
            ->values()
            ->all();
    }
}
