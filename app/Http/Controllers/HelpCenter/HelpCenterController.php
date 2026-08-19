<?php

namespace App\Http\Controllers\HelpCenter;

use App\Http\Controllers\Controller;
use App\Http\Controllers\HelpCenter\Concerns\GuardsHelpCenter;
use App\Models\HelpCenterEmailAddress;
use App\Models\HelpCenterInbox;
use App\Models\HelpCenterSpace;
use App\Services\HelpCenter\HelpCenterNavigation;
use App\Services\HelpCenter\HelpCenterOnboarding;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * The Help Center's own screens once setup is done
 * (docs/features/help-center.md §13, §14).
 *
 * Overview, Conversations and Inboxes — §14's three main navigation entries. Overview is also
 * the module's front door, and the front door is where the decision "wizard or application?" is
 * made (§1).
 */
class HelpCenterController extends Controller
{
    use GuardsHelpCenter;

    public function __construct(private readonly HelpCenterOnboarding $onboarding) {}

    /**
     * GET /help-center — Overview (§14).
     *
     * "When a user opens Help Desk for the first time and no Space has been configured, the
     * system must launch the onboarding experience instead of displaying an empty Help Desk
     * interface" (§1). That sentence is this redirect.
     */
    public function index(): View|RedirectResponse
    {
        $workspace = $this->helpCenterWorkspace();

        if (! $this->onboarding->isComplete()) {
            return redirect()->route('help-center.setup');
        }

        return view('help-center.overview', [
            'workspace' => $workspace,
            'section' => 'overview',
            'stats' => $this->stats(),
        ]);
    }

    /**
     * GET /help-center/conversations (§14).
     *
     * Built as the screen with its empty state, because there are no conversations to show yet
     * (HC-D8). The filters §14 lists are the same six views a Space carries, so they are drawn
     * from the same config rather than restated here.
     */
    public function conversations(): View|RedirectResponse
    {
        $workspace = $this->helpCenterWorkspace();

        if (! $this->onboarding->isComplete()) {
            return redirect()->route('help-center.setup');
        }

        return view('help-center.conversations', [
            'workspace' => $workspace,
            'section' => 'conversations',
            'filters' => $this->conversationFilters(),
        ]);
    }

    /** GET /help-center/inboxes (§14). */
    public function inboxes(HelpCenterNavigation $nav): View|RedirectResponse
    {
        $workspace = $this->helpCenterWorkspace();

        if (! $this->onboarding->isComplete()) {
            return redirect()->route('help-center.setup');
        }

        /*
         * Still reachable, deliberately not in the navigation (P3 §20).
         *
         * "Inboxes" was replaced by "Spaces" as the management entry, but this screen is where
         * an Inbox's inbound address and its customer-facing addresses are managed, and that
         * has no other home yet. It keeps its own section marker so it does not light up the
         * Spaces entry while you are on it.
         */
        return view('help-center.inboxes', [
            'workspace' => $workspace,
            'section' => 'inboxes',
            'inboxes' => $nav->inboxes(Auth::user()),
        ]);
    }

    /**
     * The Overview's counts (§14).
     *
     * Configuration only — Spaces, Inboxes, connected addresses. §14's operational figures
     * (open conversations, response times, workload) are listed there as "future examples" and
     * count things that do not exist yet; a zero beside "Response time" would be a measurement,
     * not a placeholder.
     *
     * @return array<int, array<string, mixed>>
     */
    private function stats(): array
    {
        return [
            ['label' => 'Spaces', 'value' => HelpCenterSpace::query()->active()->count(), 'icon' => 'grid'],
            ['label' => 'Inboxes', 'value' => HelpCenterInbox::query()->active()->count(), 'icon' => 'inbox'],
            ['label' => 'Connected addresses',
                'value' => HelpCenterEmailAddress::query()->count(), 'icon' => 'link'],
        ];
    }

    /**
     * §14's filter list: All, plus the six system views.
     *
     * @return array<int, array<string, string>>
     */
    private function conversationFilters(): array
    {
        $filters = [['key' => 'all', 'label' => 'All']];

        foreach ((array) config('help-center.space_views') as $key => $view) {
            $filters[] = ['key' => $key, 'label' => $view['label']];
        }

        return $filters;
    }
}
