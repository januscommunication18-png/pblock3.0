<?php

namespace App\Http\Controllers\HelpCenter;

use App\Http\Controllers\Controller;
use App\Models\HelpCenterRating;
use App\Models\HelpCenterRatingSettings;
use App\Services\HelpCenter\RatingManager;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The customer's rating page (docs/features/help-center.md, P56 §9).
 *
 * The ONLY unauthenticated, un-tenanted screen in this module, and it is written like it.
 *
 * ## What it deliberately does not do
 *
 * It never shows the ticket. Not the subject, not the thread, not the agent's name — only "how
 * was your support experience?". The token arrived by email at an address we do not control, and
 * an email account is forwarded, shared and breached often enough that a leaked token must cost
 * the customer nothing but a stray star.
 *
 * It never confirms whether a token EXISTED. A wrong token and an expired one both land on the
 * same closed page, because "that link is not one of ours" is an oracle somebody can enumerate
 * against.
 *
 * ## Why `withoutGlobalScopes`
 *
 * There is no tenant here: nobody is signed in and no workspace has been resolved. The token IS
 * the scope — 48 random characters, unique across the table — and the row it finds carries its
 * own `tenant_id`. This is the one place in the module that reads across tenancy, and it is safe
 * only because the lookup key is unguessable.
 */
class PublicRatingController extends Controller
{
    /** GET /rating/{token} */
    public function show(string $token): View
    {
        $rating = $this->find($token);

        if ($rating === null) {
            return view('help-center.rating', ['state' => 'invalid']);
        }

        $settings = HelpCenterRatingSettings::for($rating->space);

        if ($rating->isCancelled()) {
            return view('help-center.rating', ['state' => 'invalid']);
        }

        if ($rating->isExpired()) {
            return view('help-center.rating', ['state' => 'expired']);
        }

        // Answered and the Space does not allow changes — say thank you rather than "invalid",
        // which would leave somebody who answered last week wondering whether it saved (§16).
        if ($rating->isAnswered() && ! $settings->allow_change) {
            return view('help-center.rating', [
                'state' => 'done',
                'rating' => $rating,
                'settings' => $settings,
            ]);
        }

        return view('help-center.rating', [
            'state' => 'ask',
            'rating' => $rating,
            'settings' => $settings,
            'spaceName' => $rating->space?->name,
        ]);
    }

    /** POST /rating/{token} */
    public function store(Request $request, string $token, RatingManager $ratings): View
    {
        $rating = $this->find($token);

        if ($rating === null || $rating->isCancelled()) {
            return view('help-center.rating', ['state' => 'invalid']);
        }

        if ($rating->isExpired()) {
            return view('help-center.rating', ['state' => 'expired']);
        }

        $settings = HelpCenterRatingSettings::for($rating->space);

        // §16 again, on the way in. The GET above hides the form; this refuses the write, because
        // a form that is merely hidden is a form a second tab still holds open.
        if ($rating->isAnswered() && ! $settings->allow_change) {
            return view('help-center.rating', ['state' => 'done', 'rating' => $rating, 'settings' => $settings]);
        }

        $points = $settings->points();

        $data = $request->validate([
            'score' => ['required', 'integer', 'min:1', 'max:'.$points],
            'comment' => ['nullable', 'string', 'max:4000'],
        ]);

        $raw = (int) $data['score'];
        $comment = $data['comment'] ?? null;

        /*
         * The comment requirement is enforced HERE as well as marked on the form.
         *
         * "Required for low ratings only" is a rule about the score somebody just picked, which
         * the browser knows and the server must not take on trust.
         */
        if ($settings->commentRequired($settings->normalise($raw)) && trim((string) $comment) === '') {
            return view('help-center.rating', [
                'state' => 'ask',
                'rating' => $rating,
                'settings' => $settings,
                'spaceName' => $rating->space?->name,
                'error' => 'Please tell us a little about what happened.',
                'score' => $raw,
                'comment' => $comment,
            ]);
        }

        $ratings->submit($rating, $raw, $comment);

        return view('help-center.rating', [
            'state' => 'thanks',
            'rating' => $rating->fresh(),
            'settings' => $settings,
        ]);
    }

    /**
     * The token's row, or null.
     *
     * Length-checked before the query: a two-character token is not a lookup worth making, and
     * refusing it here keeps the table out of reach of anything but a well-formed guess.
     */
    private function find(string $token): ?HelpCenterRating
    {
        if (strlen($token) !== 48) {
            return null;
        }

        return HelpCenterRating::query()
            ->withoutGlobalScopes()
            ->with(['space', 'request'])
            ->where('token', $token)
            ->first();
    }
}
