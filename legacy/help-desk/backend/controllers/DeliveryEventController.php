<?php

namespace App\Http\Controllers\HelpDesk;

use App\Http\Controllers\Controller;
use App\Jobs\RecordEmailDeliveryEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The outbound delivery webhook (docs/features/help-desk.md — Phase 2, FR-2.9).
 *
 * The same provider that delivers inbound mail reports back what happened to what we sent. It
 * authenticates exactly like the inbound endpoint — an HMAC of the raw body, one shared secret,
 * one verification path — because two ways of proving the same thing is one more thing to get
 * wrong on an endpoint with no session behind it.
 */
class DeliveryEventController extends Controller
{
    use VerifiesInboundSignature;

    /** POST /help-desk/email/delivery */
    public function __invoke(Request $request): JsonResponse
    {
        if ($refusal = $this->refuseUnsigned($request)) {
            return $refusal;
        }

        $payload = $request->json()->all();

        // A delivery event that names no message is about nothing we can act on.
        if (! is_array($payload) || ($payload['message_id'] ?? null) === null) {
            return response()->json(['ok' => false, 'error' => 'A message_id is required.'], 422);
        }

        RecordEmailDeliveryEvent::dispatch($payload);

        return response()->json(['ok' => true], 202);
    }
}
