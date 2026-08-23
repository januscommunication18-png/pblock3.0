<?php

namespace App\Http\Controllers\HelpCenter;

use App\Http\Controllers\Controller;
use App\Http\Controllers\HelpCenter\Concerns\GuardsHelpCenter;
use App\Services\HelpCenter\HelpCenterOnboarding;
use App\Services\HelpCenter\RequestQueue;
use App\Services\HelpCenter\RequestViews;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * The Help Center's own queue (docs/features/help-center.md, P21).
 *
 * `/help-center/inbox` and `/help-center/inbox/{view}` — Inbox, Unassigned, Mine, Draft,
 * Assigned, Closed and Spam, ACROSS EVERY ACTIVE SPACE.
 *
 * These were six chips on one Space's Inbox screen. Two things were wrong with that: they are
 * the questions an agent opens the Help Center to ask ("what is mine?", "what has nobody picked
 * up?") and they were two clicks and a Space deep; and an agent working three Spaces had three
 * Inboxes to check with no screen that added them up. So they are navigation now, and each one
 * is a URL — which is also what makes them shareable, bookmarkable and survivable across a
 * refresh, none of which a chip in a component's local state is.
 *
 * The view is a URL SEGMENT validated against config, so an unknown one 404s rather than
 * rendering an empty queue — the same rule the Space's sections and Settings pages follow.
 *
 * Filtering is done in SQL, not in the browser: the view is the URL, so the server sends exactly
 * the rows that belong to it.
 *
 * The payload is built by `RequestQueue`, shared with a Space's own copy of these views (P22) —
 * one screen, one shape, two scopes.
 */
class InboxQueueController extends Controller
{
    use GuardsHelpCenter;

    public function __construct(
        private readonly HelpCenterOnboarding $onboarding,
        private readonly RequestViews $views,
        private readonly RequestQueue $queue,
    ) {}

    /** GET /help-center/inbox and /help-center/inbox/{view} */
    public function show(?string $view = null): View|RedirectResponse
    {
        $workspace = $this->helpCenterWorkspace();

        // Same front-door rule as Overview: a workspace with no Space has nothing to queue, and
        // an empty grid is a worse answer than the wizard it still needs to finish.
        if (! $this->onboarding->isComplete()) {
            return redirect()->route('help-center.setup');
        }

        $view ??= RequestViews::INBOX;
        abort_if($view !== RequestViews::INBOX && RequestViews::item($view) === null, 404);

        return view('help-center.inbox', [
            'workspace' => $workspace,
            // Lights the queue group in the sidebar. WHICH row is lit is read from the route by
            // HelpCenterNavigation::currentView(), not from this.
            'section' => 'inbox',
            'view' => $view,
            'viewLabel' => RequestViews::label($view),
            'bootstrap' => $this->queue->payload($view, Auth::user()),
        ]);
    }

    /**
     * GET /help-center/inbox/rows/{view?} — one view's rows, as JSON (P67).
     *
     * What a live update sends the cross-Space queue back for. `RequestQueue::rows()` is the
     * same call the page was rendered from, so "longest waiting first", the view's own filter
     * and each row's `manageable` flag stay one implementation.
     *
     * `spaceOptions` travels with them, and that is not decoration: the row menu's status and
     * assignee pickers are keyed by Space, and they are built FROM the rows — so a ticket
     * arriving from a Space that had nothing in this view a moment ago would otherwise land
     * with a menu that could offer it neither.
     */
    public function rows(?string $view = null): JsonResponse
    {
        $this->helpCenterWorkspace();

        $view ??= RequestViews::INBOX;
        abort_if($view !== RequestViews::INBOX && RequestViews::item($view) === null, 404);

        $rows = $this->queue->rows($view, Auth::user());

        return response()->json([
            'ok' => true,
            'rows' => $rows,
            'spaceOptions' => $this->queue->spaceOptionsFor($rows),
        ]);
    }

    /**
     * GET /help-center/inbox/counts — the navigation's numbers, on their own.
     *
     * "The counts should update dynamically as conversations are assigned, reassigned, closed,
     * or reopened" (P21). The navigation is server-rendered on a full page load, but the queue
     * screens change rows in place, so after one of those changes the bar above them would be
     * stale until the next navigation.
     *
     * A re-read rather than a delta applied in JavaScript: the browser holds one view's rows and
     * the bar counts three views across every Space, so a client-side adjustment would be a
     * second implementation of `RequestViews` — and the first one to drift.
     *
     * BOTH scopes in one answer (P22). The sidebar shows the workspace's numbers at the top and
     * each Space's own underneath, and one Request changing moves both — so one call repaints
     * the whole bar rather than the page having to know which half it is looking at.
     */
    public function counts(): JsonResponse
    {
        $this->helpCenterWorkspace();

        $me = (int) Auth::id();

        return response()->json([
            'ok' => true,
            'counts' => $this->views->counts($me),
            'spaces' => $this->views->countsBySpace($me),
        ]);
    }
}
