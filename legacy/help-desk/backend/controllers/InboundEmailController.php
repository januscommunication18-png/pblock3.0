<?php

namespace App\Http\Controllers\HelpDesk;

use App\Http\Controllers\Controller;
use App\Jobs\IngestInboundEmail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The inbound email webhook (docs/features/help-desk.md — Phase 2, FR-2.4; decision H19).
 *
 * One of the two unauthenticated write endpoints in the application. What stands in for a
 * session is the body signature — see VerifiesInboundSignature, which both webhooks share so
 * they cannot disagree about what a valid caller is.
 *
 * It returns 202 and does the work on the queue: a provider that times out retries, and retries
 * are how duplicate conversations are made. (Harmless here — ingestion is idempotent — but a
 * webhook that answers slowly is a webhook that gets called twice for no reason.)
 */
class InboundEmailController extends Controller
{
    use VerifiesInboundSignature;

    /** POST /help-desk/email/inbound */
    public function __invoke(Request $request): JsonResponse
    {
        if ($refusal = $this->refuseUnsigned($request)) {
            return $refusal;
        }

        $payload = $request->json()->all();

        /*
         * The minimum that makes this a message: it was addressed somewhere. `delivered_to`
         * counts as well as `to`, because a forwarded message carries the address that routes it
         * only there (Inbound Email requirements §2). Anything more — who it is from, how it
         * threads, whether anybody owns the address — is the ingestor's judgement, and a webhook
         * that validated it would be a second place those rules live.
         */
        if (! is_array($payload) || (($payload['to'] ?? null) === null && ($payload['delivered_to'] ?? null) === null)) {
            return response()->json(['ok' => false, 'error' => 'A recipient is required.'], 422);
        }

        IngestInboundEmail::dispatch($payload);

        return response()->json(['ok' => true], 202);
    }
}
