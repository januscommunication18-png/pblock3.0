<?php

namespace App\Http\Controllers\HelpCenter;

use App\Http\Controllers\Controller;
use App\Http\Controllers\HelpCenter\Concerns\GuardsHelpCenter;
use App\Models\HelpCenterInboundTest;
use App\Models\HelpCenterSpace;
use App\Services\HelpCenter\Inbound\InboundTestRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * The Space Overview's inbound test card (docs/features/help-center.md, P7).
 *
 * Two endpoints: start one, and poll the latest. Polling rather than websockets because the
 * answer arrives from an EMAIL round trip measured in tens of seconds — a broadcast channel
 * would be more machinery than a two-minute wait justifies, and it would add a Reverb
 * dependency to the one screen whose job is diagnosing infrastructure.
 */
class InboundTestController extends Controller
{
    use GuardsHelpCenter;

    public function __construct(private readonly InboundTestRunner $runner) {}

    /** POST /help-center/spaces/{space}/inbound-test */
    public function store(HelpCenterSpace $space): JsonResponse
    {
        $this->helpCenterWorkspace();
        // Running a test sends mail from the workspace's address, so it is a management action.
        abort_unless(Auth::user()->can('update', $space), 403);

        $inbox = $space->inboxes()->active()->orderBy('position')->first();

        if ($inbox === null) {
            return response()->json([
                'ok' => false,
                'message' => 'This Space has no Inbox to test.',
            ], 422);
        }

        // One at a time: a second probe in flight makes the card ambiguous about which result
        // it is showing.
        $running = $this->latest($space);

        if ($running !== null && $running->isRunning() && ! $running->hasTimedOut()) {
            return response()->json(['ok' => true, 'test' => $running->toPayload()]);
        }

        try {
            $test = $this->runner->start($inbox, Auth::user());
        } catch (RuntimeException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'test' => $test->toPayload()]);
    }

    /**
     * GET /help-center/spaces/{space}/inbound-test — what the card polls.
     *
     * The timeout is resolved HERE, on read. A test whose two minutes have elapsed is written
     * to `timeout` at the moment somebody looks, rather than by a scheduled job that would be
     * a second source of truth — and that would leave tests hanging forever whenever the queue
     * was down, which is precisely when this screen is being used.
     */
    public function show(HelpCenterSpace $space): JsonResponse
    {
        $this->helpCenterWorkspace();
        abort_unless(Auth::user()->can('view', $space), 404);

        $test = $this->latest($space);

        if ($test !== null && $test->hasTimedOut()) {
            $test->forceFill([
                'status' => HelpCenterInboundTest::STATUS_TIMEOUT,
                'failed_at' => now(),
                'failure_reason' => 'No forwarded message arrived within '
                    .$test->timeoutSeconds().' seconds.',
            ])->save();
        }

        return response()->json([
            'ok' => true,
            'test' => $test?->toPayload(),
            // The last PASSED test, kept separately so the card can still say "last verified
            // on…" while a fresh attempt is failing.
            'last_passed' => $this->lastPassed($space)?->toPayload(),
        ]);
    }

    private function latest(HelpCenterSpace $space): ?HelpCenterInboundTest
    {
        return HelpCenterInboundTest::query()
            ->where('help_center_space_id', $space->id)
            ->latest('id')
            ->first();
    }

    private function lastPassed(HelpCenterSpace $space): ?HelpCenterInboundTest
    {
        return HelpCenterInboundTest::query()
            ->where('help_center_space_id', $space->id)
            ->where('status', HelpCenterInboundTest::STATUS_PASSED)
            ->latest('id')
            ->first();
    }
}
