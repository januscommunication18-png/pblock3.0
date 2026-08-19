<?php

namespace App\Http\Controllers\HelpDesk;

use App\Http\Controllers\Controller;
use App\Jobs\IngestInboundEmail;
use App\Services\HelpDesk\PostmarkInboundPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Postmark's inbound webhook (Inbound Email requirements §3, §11).
 *
 * Postmark does NOT sign inbound webhooks — its documented practice is a secret in the URL — so
 * this endpoint authenticates on the token in its path, compared in constant time, and refuses
 * everything when no token is configured. That is weaker than the body signature the generic
 * endpoint requires, and it is weaker because the provider decided so; what keeps it honest is
 * that the token is the whole of the URL's unguessable part and the endpoint does nothing at all
 * without it.
 *
 * Past authentication this is deliberately thin: map the payload, dispatch the same job the
 * generic endpoint dispatches, answer 202. A provider gets to decide what its webhook looks
 * like, not what ingestion is.
 */
class PostmarkInboundController extends Controller
{
    /** POST /help-desk/email/inbound/postmark/{token} */
    public function __invoke(Request $request, string $token, PostmarkInboundPayload $mapper): JsonResponse
    {
        $expected = (string) config('help-desk.inbound.postmark_token', '');

        if ($expected === '') {
            // Off, not open. An ingestion endpoint that accepts unauthenticated requests lets
            // anybody file a support conversation as anybody.
            return response()->json(['ok' => false, 'error' => 'Postmark inbound is not configured.'], 503);
        }

        if (! hash_equals($expected, $token)) {
            // Logged without the body: a wrong token is either a misconfiguration worth seeing
            // or an attempt worth counting, and neither needs the message recorded.
            Log::warning('help_desk.inbound.postmark.rejected', [
                'ip' => $request->ip(),
            ]);

            return response()->json(['ok' => false, 'error' => 'Invalid token.'], 401);
        }

        $payload = $mapper->normalize((array) $request->json()->all());

        /*
         * A message has to have arrived SOMEWHERE, or there is nothing to route it by (§11.1).
         * Both fields are checked because a forwarded message may carry the generated address in
         * only one of them.
         */
        if ($payload['to'] === [] && $payload['delivered_to'] === []) {
            return response()->json(['ok' => false, 'error' => 'A recipient is required.'], 422);
        }

        IngestInboundEmail::dispatch($payload);

        return response()->json(['ok' => true], 202);
    }
}
