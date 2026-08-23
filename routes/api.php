<?php

use App\Http\Controllers\HelpCenter\PostmarkBounceController;
use App\Http\Controllers\HelpCenter\PostmarkInboundController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API / webhooks
|--------------------------------------------------------------------------
| Stateless routes. No session, no CSRF, no `auth` — the callers here are other people's
| servers, which have no cookies to send and no login to perform. Each route authenticates
| itself; see PostmarkInboundController for how the inbound webhook does it.
|
| Registered in bootstrap/app.php under the `/api` prefix.
*/

/*
 * Postmark Inbound (docs/features/help-center.md, P6).
 *
 * The token segment is OPTIONAL because Postmark's webhook URL field accepts credentials
 * either in the path or as basic auth, and different deployments use different ones. Both are
 * checked against the same secret; with no secret configured the endpoint refuses everything.
 */
Route::post('/webhooks/postmark/inbound/{token?}', PostmarkInboundController::class)
    ->name('webhooks.postmark.inbound');

/*
 * Postmark's BOUNCE webhook (P10).
 *
 * The missing half of sending an invitation: Postmark accepts the SMTP transaction and only
 * afterwards decides the address is undeliverable, so the application's send succeeds and
 * nothing ever learns otherwise. This is how it finds out.
 */
Route::post('/webhooks/postmark/bounce/{token?}', PostmarkBounceController::class)
    ->name('webhooks.postmark.bounce');
