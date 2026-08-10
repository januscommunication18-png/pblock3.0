<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * SSO entry point (AUTH-004). Provider/domain configuration is progressive:
 * a domain -> IdP map in config/services.php ('sso.domains') routes known domains,
 * unknown domains get clear guidance rather than a dead end.
 */
class SsoController extends Controller
{
    public function start(Request $request): RedirectResponse
    {
        $email  = strtolower(trim((string) $request->input('email')));
        $domain = str_contains($email, '@') ? substr(strrchr($email, '@'), 1) : null;

        $map = (array) config('services.sso.domains', []);

        if ($domain && isset($map[$domain])) {
            // Configured IdP entry URL for this domain.
            return redirect()->away($map[$domain]);
        }

        return redirect()->route('signup')->withErrors([
            'email' => 'Single Sign-On is not yet configured for this email domain. Contact your workspace admin or use email/Google/GitHub.',
        ]);
    }
}
