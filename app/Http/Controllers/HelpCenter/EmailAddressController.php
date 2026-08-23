<?php

namespace App\Http\Controllers\HelpCenter;

use App\Http\Controllers\Controller;
use App\Http\Controllers\HelpCenter\Concerns\GuardsHelpCenter;
use App\Http\Requests\HelpCenter\StoreEmailAddressRequest;
use App\Models\HelpCenterEmailAddress;
use App\Models\HelpCenterInboundTest;
use App\Models\HelpCenterInbox;
use App\Services\HelpCenter\HelpCenterInboxManager;
use App\Services\HelpCenter\Inbound\InboundTestRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * The customer-facing addresses on an Inbox (docs/features/help-center.md §6, §7).
 *
 * "Users must be able to add multiple email addresses to the same Inbox" and each row carries a
 * Remove action — that is this controller's whole surface.
 */
class EmailAddressController extends Controller
{
    use GuardsHelpCenter;

    public function __construct(
        private readonly HelpCenterInboxManager $inboxes,
        private readonly InboundTestRunner $tests,
    ) {}

    /** POST /help-center/inboxes/{inbox}/addresses */
    public function store(StoreEmailAddressRequest $request, HelpCenterInbox $inbox): JsonResponse
    {
        $this->helpCenterWorkspace();
        abort_unless($inbox->space !== null && Auth::user()->can('update', $inbox->space), 403);

        $address = $this->inboxes->addAddress(
            Auth::user(),
            $inbox,
            (string) $request->validated('email'),
            $request->validated('name'),
        );

        return response()->json([
            'ok' => true,
            'address' => $address->toPayload(),
            'message' => 'Email address added.',
        ]);
    }

    /**
     * DELETE /help-center/inboxes/{inbox}/addresses/{address}
     *
     * The address is checked to belong to the Inbox in the URL, not merely to exist: without
     * that, an id from another Inbox in the same workspace would be deleted by whoever may
     * manage THIS one, which is a Space Lead reaching into a Space they do not lead.
     */
    public function destroy(HelpCenterInbox $inbox, HelpCenterEmailAddress $address): JsonResponse
    {
        $this->helpCenterWorkspace();
        abort_unless($inbox->space !== null && Auth::user()->can('update', $inbox->space), 403);
        abort_unless((int) $address->help_center_inbox_id === (int) $inbox->id, 404);

        $address->delete();

        return response()->json(['ok' => true, 'message' => 'Email address removed.']);
    }

    /**
     * POST /help-center/inboxes/{inbox}/addresses/{address}/test
     *
     * "Send test email" on an address that is not verified yet (P11).
     *
     * There is no separate verification handshake to build: an address becomes Verified the
     * moment a message forwarded from it reaches the ingestor (§11), and that is exactly what a
     * passing inbound test proves. So this is the SAME probe the Space Overview's card sends,
     * pointed at one row — not a second mechanism with its own idea of what "verified" means.
     */
    public function test(HelpCenterInbox $inbox, HelpCenterEmailAddress $address): JsonResponse
    {
        $this->helpCenterWorkspace();
        // Sending mail from the workspace's address is a management action, the same one the
        // Space Overview's card is gated on.
        abort_unless($inbox->space !== null && Auth::user()->can('update', $inbox->space), 403);
        abort_unless((int) $address->help_center_inbox_id === (int) $inbox->id, 404);

        // One probe per address at a time. A second in flight makes the row ambiguous about
        // which result it is showing — the same rule InboundTestController applies per Space.
        $running = $this->latestFor($address)?->resolveTimeout();

        if ($running !== null && $running->isRunning()) {
            return response()->json(['ok' => true, 'test' => $running->toPayload(), 'address' => $address->toPayload()]);
        }

        try {
            $test = $this->tests->start($inbox, Auth::user(), $address);
        } catch (RuntimeException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok' => true,
            'test' => $test->toPayload(),
            'address' => $address->toPayload(),
        ]);
    }

    /**
     * GET /help-center/inboxes/{inbox}/addresses/{address}/test — what the row polls.
     *
     * Returns the address alongside the test, because a pass CHANGES the address: the ingestor
     * marks it Verified on the way through. Sending back only the test would leave the row's
     * badge still reading "Setup Required" beside a result that says it works, and the only fix
     * for that would be a page reload.
     */
    public function testStatus(HelpCenterInbox $inbox, HelpCenterEmailAddress $address): JsonResponse
    {
        $this->helpCenterWorkspace();
        abort_unless($inbox->space !== null && Auth::user()->can('view', $inbox->space), 404);
        abort_unless((int) $address->help_center_inbox_id === (int) $inbox->id, 404);

        return response()->json([
            'ok' => true,
            'test' => $this->latestFor($address)?->resolveTimeout()?->toPayload(),
            'address' => $address->fresh()->toPayload(),
        ]);
    }

    /**
     * The most recent probe sent to this address.
     *
     * Matched on the address STRING rather than an id, because that is what the test row keeps
     * — `test_email_address` is a snapshot of where the probe was actually sent, which stays
     * true even if the address row is later removed and re-added.
     */
    private function latestFor(HelpCenterEmailAddress $address): ?HelpCenterInboundTest
    {
        return HelpCenterInboundTest::query()
            ->where('help_center_inbox_id', $address->help_center_inbox_id)
            ->where('test_email_address', $address->email)
            ->latest('id')
            ->first();
    }
}
