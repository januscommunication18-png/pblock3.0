<?php

namespace App\Http\Controllers\HelpCenter;

use App\Http\Controllers\Controller;
use App\Jobs\IngestInboundEmail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Postmark's inbound webhook (docs/features/help-center.md, P6).
 *
 * Does three things and no more: authenticate, queue, answer 200. Everything else is
 * IngestInboundEmail's, because Postmark treats a slow or non-2xx response as a failure and
 * retries — so work done inside this request is work that can be done several times.
 */
class PostmarkInboundController extends Controller
{
    /** POST /api/webhooks/postmark/inbound/{token?} */
    public function __invoke(Request $request, ?string $token = null): JsonResponse
    {
        if (! $this->authentic($request, $token)) {
            /*
             * 404, not 401.
             *
             * An unauthenticated caller should not learn that this endpoint exists. A 401 says
             * "right URL, wrong key" and invites guessing; a 404 says nothing.
             */
            Log::warning('help-center.inbound.rejected', ['ip' => $request->ip()]);

            abort(404);
        }

        /*
         * Remember that Postmark reached us at all.
         *
         * This one timestamp separates the two failures that look identical from the outside:
         * "your forwarding rule is not firing" and "Postmark has no webhook URL configured".
         * Without it the inbound test can only ever blame forwarding, which sent people looking
         * in the wrong place for a whole afternoon.
         *
         * Cache rather than a table: it is a single global fact with no history worth keeping,
         * and it must survive nothing more than the current deployment.
         */
        Cache::forever('help-center.last_inbound_webhook_at', now()->toIso8601String());

        $payload = $request->all();

        if (! is_array($payload) || $payload === []) {
            return response()->json(['ok' => false, 'message' => 'Empty payload.'], 422);
        }

        IngestInboundEmail::dispatch($payload);

        // 200 immediately. Postmark only needs to know we have it.
        return response()->json(['ok' => true]);
    }

    /**
     * Is this really Postmark?
     *
     * Postmark does NOT sign inbound webhooks, so the only thing available is a shared secret.
     * Two ways to present it, because Postmark's inbound URL field accepts either shape:
     *
     *   1. in the path   — https://host/api/webhooks/postmark/inbound/<secret>
     *   2. as basic auth — https://user:<secret>@host/api/webhooks/postmark/inbound
     *
     * `hash_equals` on both, so neither can be probed a character at a time.
     *
     * **Unset means closed.** A missing secret refuses everything rather than accepting
     * everything — "not configured" must never mean "open to the internet", which is the one
     * mistake that turns this endpoint into a way to write rows into any workspace.
     */
    private function authentic(Request $request, ?string $token): bool
    {
        $secret = (string) config('help-center.inbound_secret');

        if ($secret === '') {
            Log::warning('help-center.inbound.no_secret_configured');

            return false;
        }

        if ($token !== null && hash_equals($secret, $token)) {
            return true;
        }

        $password = (string) $request->getPassword();

        return $password !== '' && hash_equals($secret, $password);
    }
}
