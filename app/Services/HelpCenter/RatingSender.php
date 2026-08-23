<?php

namespace App\Services\HelpCenter;

use App\Mail\HelpCenterRatingRequestMail;
use App\Models\HelpCenterEmailAddress;
use App\Models\HelpCenterEmailTemplate;
use App\Models\HelpCenterRating;
use App\Models\HelpCenterRatingSettings;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Putting a rating request in front of a customer (docs/features/help-center.md, P56).
 *
 * Separate from `RatingManager` because they answer different questions: the manager decides
 * WHETHER and WHEN, this one does the sending. Keeping them apart is what lets the sweep send a
 * request raised an hour ago without re-running the eligibility rules on a ticket that has moved
 * on since.
 */
class RatingSender
{
    public function __construct(
        private readonly EmailTemplateRenderer $templates,
        private readonly SpaceSender $sender,
    ) {}

    /**
     * Send one request. Returns false when it could not go — logged, never thrown.
     *
     * The row is already saved; a mail failure must not lose the record that we meant to ask, or
     * the sweep would raise a second request on its next pass.
     */
    public function send(HelpCenterRating $rating, bool $reminder = false): bool
    {
        $request = $rating->request;
        $space = $rating->space;

        if ($request === null || $space === null) {
            return false;
        }

        $to = trim((string) $request->customer_email);

        if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        try {
            $support = $this->supportAddress($space);

            $composed = $this->templates->compose(
                $space,
                HelpCenterEmailTemplate::TYPE_RATING_REQUEST,
                $this->templates->variables(
                    $request,
                    $space,
                    $rating->agent,
                    null,
                    $support,
                    $this->link($rating),
                ),
            );

            $subject = $composed['subject'];

            // A reminder says so in the subject. The same mail with the same subject arriving
            // twice reads as a system fault rather than a nudge.
            if ($reminder) {
                $subject = 'Reminder: '.$subject;
            }

            // The Space's own sender (P65) — a rating request is a customer-facing ticket email
            // like any other, and one arriving from a different name than the conversation it is
            // asking about reads as a third party harvesting feedback.
            $identity = $this->sender->forSpace($space);

            Mail::to($to)->send(new HelpCenterRatingRequestMail(
                $subject,
                $composed['html'],
                $support,
                $identity['from_email'],
                $identity['from_name'],
                $identity['reply_to_name'],
            ));

            $rating->forceFill($reminder
                ? ['reminders_sent' => (int) $rating->reminders_sent + 1]
                : ['sent_at' => now()],
            )->save();

            Log::info('help-center.rating.sent', [
                'rating_id' => $rating->id,
                'request_id' => $request->id,
                'reminder' => $reminder,
            ]);

            return true;
        } catch (Throwable $e) {
            Log::error('help-center.rating.send_failed', [
                'rating_id' => $rating->id,
                'reminder' => $reminder,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /** The customer's link — a signed-in-free URL carrying only the token. */
    public function link(HelpCenterRating $rating): string
    {
        return route('help-center.rating.show', ['token' => $rating->token]);
    }

    /** The Space's own customer-facing address, preferring a verified one. */
    private function supportAddress($space): ?string
    {
        $addresses = $space->inboxes->first()?->emailAddresses;

        if ($addresses === null || $addresses->isEmpty()) {
            return null;
        }

        return ($addresses->firstWhere('status', HelpCenterEmailAddress::STATUS_VERIFIED)
            ?? $addresses->first())?->email;
    }
}
