<?php

namespace App\Http\Controllers\HelpDesk;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The shared secret check both mail webhooks stand behind (FR-2.4, FR-2.9).
 *
 * Extracted because there are now two endpoints the outside world can reach, and they must
 * agree exactly about what a valid caller is. A second implementation of "is this signed?" is
 * a second chance to accept something that is not.
 *
 * The check itself:
 *   - a secret must be configured, or the endpoint refuses everything (503). "Not configured"
 *     must never mean "accepts anything";
 *   - the body must carry an HMAC-SHA256 of ITSELF. A bearer token would prove the caller knows
 *     a secret; a body signature proves the BODY is the one that was signed, which is what
 *     matters when the payload decides who a message is from;
 *   - compared in constant time, because comparing secrets with `===` leaks their prefix.
 */
trait VerifiesInboundSignature
{
    /** A response to return, or null when the request may proceed. */
    protected function refuseUnsigned(Request $request): ?JsonResponse
    {
        $secret = (string) config('help-desk.inbound.secret', '');

        if ($secret === '') {
            return response()->json(['ok' => false, 'error' => 'Inbound email is not configured.'], 503);
        }

        if (! $this->signatureMatches($request, $secret)) {
            // Logged without the body: an unsigned request is either a misconfiguration worth
            // seeing or an attempt worth counting, and neither needs the payload recorded.
            Log::warning('help_desk.inbound.rejected', [
                'ip' => $request->ip(),
                'path' => $request->path(),
            ]);

            return response()->json(['ok' => false, 'error' => 'Invalid signature.'], 401);
        }

        return null;
    }

    private function signatureMatches(Request $request, string $secret): bool
    {
        $header = (string) config('help-desk.inbound.signature_header', 'X-PB-Signature');
        $provided = trim((string) $request->header($header, ''));

        if ($provided === '') {
            return false;
        }

        // Providers differ on whether they prefix the algorithm; both forms are accepted rather
        // than making one of them a support ticket of its own.
        $provided = str_contains($provided, '=')
            ? trim(substr($provided, strpos($provided, '=') + 1))
            : $provided;

        return hash_equals(hash_hmac('sha256', $request->getContent(), $secret), $provided);
    }
}
