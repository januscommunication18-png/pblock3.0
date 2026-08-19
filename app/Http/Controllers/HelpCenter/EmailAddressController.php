<?php

namespace App\Http\Controllers\HelpCenter;

use App\Http\Controllers\Controller;
use App\Http\Controllers\HelpCenter\Concerns\GuardsHelpCenter;
use App\Http\Requests\HelpCenter\StoreEmailAddressRequest;
use App\Models\HelpCenterEmailAddress;
use App\Models\HelpCenterInbox;
use App\Services\HelpCenter\HelpCenterInboxManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * The customer-facing addresses on an Inbox (docs/features/help-center.md §6, §7).
 *
 * "Users must be able to add multiple email addresses to the same Inbox" and each row carries a
 * Remove action — that is this controller's whole surface.
 */
class EmailAddressController extends Controller
{
    use GuardsHelpCenter;

    public function __construct(private readonly HelpCenterInboxManager $inboxes) {}

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
}
