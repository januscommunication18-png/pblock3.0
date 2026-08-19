<?php

namespace App\Http\Controllers\HelpCenter;

use App\Http\Controllers\Controller;
use App\Http\Controllers\HelpCenter\Concerns\GuardsHelpCenter;
use App\Http\Requests\HelpCenter\StoreInboxRequest;
use App\Models\HelpCenterSpace;
use App\Services\HelpCenter\HelpCenterInboxManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Creating an Inbox inside an existing Space (docs/features/help-center.md §14).
 *
 * The wizard's step 2 is SetupController's; this is the "Create Inbox" of the Inboxes screen,
 * for a Help Center that is already running. Both go through HelpCenterInboxManager, so both
 * produce an Inbox with an inbound identifier and neither can forget to generate one.
 */
class InboxController extends Controller
{
    use GuardsHelpCenter;

    public function __construct(private readonly HelpCenterInboxManager $inboxes) {}

    /** POST /help-center/spaces/{space}/inboxes */
    public function store(StoreInboxRequest $request, HelpCenterSpace $space): JsonResponse
    {
        $this->helpCenterWorkspace();

        // Managing a Space's Inboxes is the Space Lead's right as much as the workspace
        // administrator's (§19).
        abort_unless(Auth::user()->can('update', $space), 403);

        $inbox = DB::transaction(function () use ($request, $space) {
            $inbox = $this->inboxes->create(Auth::user(), $space, $request->validated());

            foreach ((array) $request->input('addresses', []) as $address) {
                $this->inboxes->addAddress(
                    Auth::user(),
                    $inbox,
                    (string) $address['email'],
                    $address['name'] ?? null,
                );
            }

            /*
             * An Inbox created OUTSIDE the wizard is set up the moment it is made.
             *
             * `setup_completed_at` answers "has the wizard been finished for this?" (HC-D3), and
             * for an Inbox that never went through the wizard the honest answer is yes — there
             * is no outstanding step. Leaving it null would make the workspace look mid-setup
             * to any check that asks.
             */
            return $this->inboxes->completeSetup($inbox);
        });

        return response()->json([
            'ok' => true,
            'inbox' => $inbox->load('emailAddresses')->toPayload(),
            'message' => 'Inbox created.',
        ]);
    }
}
