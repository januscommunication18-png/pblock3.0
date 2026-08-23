<?php

namespace App\Http\Controllers\HelpCenter;

use App\Http\Controllers\Controller;
use App\Http\Controllers\HelpCenter\Concerns\GuardsHelpCenter;
use App\Models\HelpCenterRequest;
use App\Models\HelpCenterSpace;
use App\Services\HelpCenter\SnoozeManager;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Snoozing one Request (docs/features/help-center.md, P45).
 *
 * Its own controller rather than two more branches on `RequestController::update()`, because a
 * snooze is not a property of the ticket in the way status and priority are — it is a state with
 * its own lifecycle, its own permission sentence and its own end conditions. Bundling it into the
 * generic PATCH would mean the endpoint that changes a tag can also silence a ticket for a week.
 *
 * The requirement's "individual ticket level, not a Space-wide or bulk setting" is enforced by
 * the route shape: one Request in the URL, one Request written, no list form anywhere.
 */
class RequestSnoozeController extends Controller
{
    use GuardsHelpCenter;

    public function __construct(private readonly SnoozeManager $snooze) {}

    /**
     * POST /help-center/spaces/{space}/requests/{request}/snooze
     *
     * Also the endpoint for CHANGING an existing snooze's time or condition — see
     * `SnoozeManager::snooze()` for why that is one write rather than two.
     */
    public function store(Request $httpRequest, HelpCenterSpace $space, int $request): JsonResponse
    {
        $model = $this->authorised($space, $request);

        $data = $httpRequest->validate([
            /*
             * An ISO-8601 instant WITH its offset, computed by the browser.
             *
             * The natural-language input ("3 days", "next Monday at 8am") is parsed on the
             * client, because the client is the only party that knows what "8am" means to the
             * person typing it — the requirement asks for the workspace/user timezone and the
             * browser's is the one this app actually has. What crosses the wire is the resolved
             * moment, so there is exactly one parser and the server never has to guess a zone.
             */
            'until' => ['required', 'string', 'max:64'],
            'condition' => ['required', 'string'],
        ]);

        if (! SnoozeManager::isCondition($data['condition'])) {
            return response()->json(['ok' => false, 'message' => 'That snooze condition does not exist.'], 422);
        }

        try {
            $until = CarbonImmutable::parse($data['until']);
        } catch (Throwable) {
            return response()->json(['ok' => false, 'message' => 'That is not a time we could read.'], 422);
        }

        /*
         * Must be in the future, and the check is here rather than only in the browser.
         *
         * A dialog left open across midnight computes "tomorrow at 8am" for a tomorrow that has
         * since become today. A snooze already due is a snooze that does nothing — the ticket
         * would sit in the Snoozed view for nobody, invisible in the queue it belongs to.
         */
        if ($until->isPast()) {
            return response()->json(['ok' => false, 'message' => 'Pick a time in the future.'], 422);
        }

        // A year is not a policy, it is a typo guard: "Aug 25" parsed against the wrong year is
        // the mistake this catches, and nobody snoozes a support ticket for longer.
        if ($until->greaterThan(now()->addYear())) {
            return response()->json(['ok' => false, 'message' => 'That is more than a year away.'], 422);
        }

        $this->snooze->snooze($model, $until, $data['condition']);

        $fresh = $model->fresh()->load(['status', 'assignee', 'tags', 'snoozedBy']);

        return response()->json([
            'ok' => true,
            'request' => $fresh->toPayload(),
            'message' => $fresh->snoozeLabel().'.',
        ]);
    }

    /** DELETE …/snooze — the requirement's manual Unsnooze. */
    public function destroy(HelpCenterSpace $space, int $request): JsonResponse
    {
        $model = $this->authorised($space, $request);

        $woke = $this->snooze->unsnooze($model, SnoozeManager::REASON_MANUAL);

        $fresh = $model->fresh()->load(['status', 'assignee', 'tags', 'snoozedBy']);

        return response()->json([
            'ok' => true,
            'request' => $fresh->toPayload(),
            'message' => $woke ? 'Ticket unsnoozed.' : 'That ticket was not snoozed.',
        ]);
    }

    /**
     * The one permission gate, for all four of the requirement's verbs.
     *
     * "Only support agents with permission to manage the ticket" — which in this module is
     * permission to manage its Space, the same sentence every other write to a Request is
     * checked against. Found THROUGH the Space so a Request from another Space cannot be reached
     * by putting its id in this Space's URL.
     */
    private function authorised(HelpCenterSpace $space, int $request): HelpCenterRequest
    {
        $this->helpCenterWorkspace();
        abort_unless(Auth::user()->can('update', $space), 403);

        return HelpCenterRequest::query()->forSpace($space->id)->findOrFail($request);
    }
}
