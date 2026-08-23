<?php

namespace App\Http\Controllers\HelpCenter;

use App\Http\Controllers\Controller;
use App\Models\WorkspaceInvitation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Postmark's bounce webhook (docs/features/help-center.md, P10).
 *
 * The missing half of sending. Postmark accepts the SMTP transaction and decides afterwards
 * whether the address is deliverable, so `Mail::send` succeeds even when the mailbox does not
 * exist — the application has no way to know at send time, and without this the Members grid
 * shows "Invited" forever for an address that was rejected within seconds.
 *
 * Authenticated by the same shared-secret-in-the-URL scheme as the inbound webhook, for the same
 * reason: Postmark does not sign these, and an unauthenticated endpoint that writes to
 * invitation rows is one anybody could use to mark a colleague's invitation dead.
 */
class PostmarkBounceController extends Controller
{
    /** POST /api/webhooks/postmark/bounce/{token?} */
    public function __invoke(Request $request, ?string $token = null): JsonResponse
    {
        if (! $this->authentic($request, $token)) {
            // 404, not 401 — an unauthenticated caller should not learn this endpoint exists.
            Log::warning('help-center.bounce.rejected', ['ip' => $request->ip()]);

            abort(404);
        }

        $email = mb_strtolower(trim((string) $request->input('Email')));
        $type = (string) $request->input('Type');

        if ($email === '') {
            return response()->json(['ok' => false, 'message' => 'No recipient.'], 422);
        }

        /*
         * `withoutGlobalScopes` with no tenant filter.
         *
         * A webhook carries no tenancy context, and the address is the only thing Postmark knows
         * — it cannot tell us which workspace the invitation belonged to. The address itself is
         * the key, exactly as the inbound router uses the inbound token.
         */
        $updated = WorkspaceInvitation::query()
            ->withoutGlobalScopes()
            ->where('email', $email)
            ->where('status', WorkspaceInvitation::STATUS_PENDING)
            ->update([
                'email_status' => $type ?: 'Bounce',
                // Postmark's own wording. Ours would be a paraphrase of a message whose whole
                // value is that it came from the receiving mail server.
                'email_error' => mb_substr((string) $request->input('Description', $request->input('Details', '')), 0, 500),
                'email_failed_at' => now(),
            ]);

        Log::info('help-center.bounce.recorded', [
            'email' => $email,
            'type' => $type,
            'invitations_marked' => $updated,
        ]);

        // 200 regardless. An address we hold no invitation for is not an error on Postmark's
        // side, and a non-2xx would make it retry something that will never match.
        return response()->json(['ok' => true, 'marked' => $updated]);
    }

    /** The same secret the inbound webhook uses. Unset means closed. */
    private function authentic(Request $request, ?string $token): bool
    {
        $secret = (string) config('help-center.inbound_secret');

        if ($secret === '') {
            Log::warning('help-center.bounce.no_secret_configured');

            return false;
        }

        if ($token !== null && hash_equals($secret, $token)) {
            return true;
        }

        $password = (string) $request->getPassword();

        return $password !== '' && hash_equals($secret, $password);
    }
}
