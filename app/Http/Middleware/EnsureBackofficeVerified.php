<?php

namespace App\Http\Middleware;

use App\Services\Backoffice\BackofficeVerification;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * "The user should not be able to access /backoffice/login directly without completing the
 * security verification screen" (docs/features/backoffice-auth.md, §4).
 *
 * That sentence is this class. It guards the login screen, the login POST and the reset screens
 * — every route that comes AFTER proving mailbox control and BEFORE holding a session.
 *
 * Redirect rather than 403: somebody arriving at the login screen with an expired verification
 * has done nothing wrong, and the thing they need is the screen this sends them to.
 */
class EnsureBackofficeVerified
{
    public function __construct(private readonly BackofficeVerification $verification) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->verification->isVerified()) {
            return $next($request);
        }

        /*
         * The stale state is cleared on the way past.
         *
         * `isVerified()` already drops an expired entry, but a PENDING one — an address that
         * asked for a code and never used it — would otherwise survive and prefill the
         * verification screen for whoever sits down next.
         */
        $this->verification->forget();

        return redirect()->route('backoffice.verify.show')
            ->with('status', 'Your verification has expired. Please start again.');
    }
}
