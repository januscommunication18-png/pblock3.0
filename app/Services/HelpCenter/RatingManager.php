<?php

namespace App\Services\HelpCenter;

use App\Models\HelpCenterRating;
use App\Models\HelpCenterRatingSettings;
use App\Models\HelpCenterRequest;
use App\Models\HelpCenterRequestActivity;
use App\Models\HelpCenterStatus;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * The CSAT loop (docs/features/help-center.md, P56).
 *
 * ONE place that decides whether to ask, when to ask, and what happens when somebody answers —
 * because those three questions are asked from four different files (the status change, the
 * inbound reply that reopens a ticket, the scheduled sweep, the public submission) and four
 * copies of "is this ticket eligible?" is how a spam ticket eventually gets a survey.
 */
class RatingManager
{
    public function __construct(private readonly RequestActivity $activity) {}

    /**
     * A ticket changed status — should that raise a rating request? (§5)
     *
     * Called from the one place a Request's status moves through the workflow, so a ticket
     * resolved from the row menu, from the drawer or by a rule all behave the same.
     */
    public function statusChanged(HelpCenterRequest $request, HelpCenterStatus $status): void
    {
        $settings = $this->settings($request);

        if ($settings === null || ! $settings->enabled) {
            return;
        }

        $fires = match ($settings->trigger) {
            'resolved' => $status->system_category === 'resolved',
            'closed' => $status->isClosed() || $status->system_category === 'closed',
            'status' => (int) $settings->trigger_status_id === (int) $status->id,
            // Manual means only an agent asks; a status change is not an agent asking.
            default => false,
        };

        if ($fires) {
            $this->request($request, $settings);
        }
    }

    /**
     * Raise a request, if this ticket is eligible (§19, §20).
     *
     * Returns the row, or null with the reason logged. Null is not a failure — "we did not ask"
     * is the correct outcome for most tickets, and the log line is what makes "why did my
     * customer not get a survey?" answerable without reading this file.
     */
    public function request(HelpCenterRequest $request, ?HelpCenterRatingSettings $settings = null, bool $manual = false): ?HelpCenterRating
    {
        $settings ??= $this->settings($request);

        if ($settings === null || ! $settings->enabled) {
            return null;
        }

        $reason = $this->refusal($request, $settings, $manual);

        if ($reason !== null) {
            Log::info('help-center.rating.skipped', ['request_id' => $request->id, 'reason' => $reason]);

            return null;
        }

        $delay = max(0, (int) $settings->delay_minutes);

        $rating = HelpCenterRating::create([
            'tenant_id' => $request->tenant_id,
            'help_center_space_id' => $request->help_center_space_id,
            'help_center_request_id' => $request->id,
            'help_center_customer_id' => $request->help_center_customer_id,
            /*
             * The agent captured NOW, not read later off the ticket.
             *
             * "The final assigned agent at the time of resolution" — a ticket reassigned next
             * week must not move this score onto somebody who never touched it.
             */
            'agent_id' => $request->assignee_id,
            'token' => HelpCenterRating::newToken(),
            'rating_type' => $settings->rating_type ?? 'stars5',
            'requested_at' => now(),
            'send_after' => now()->addMinutes($delay),
            'expires_at' => $settings->expires_days === null
                ? null
                : now()->addDays((int) $settings->expires_days)->addMinutes($delay),
        ]);

        $this->activity->record(
            $request,
            HelpCenterRequestActivity::EVENT_RATING_REQUESTED,
            'rating',
            null,
            $request->customerLabel(),
            ['manual' => $manual, 'delay_minutes' => $delay],
        );

        return $rating;
    }

    /**
     * Why this ticket gets no survey — null means it does.
     *
     * The requirement's §20 exclusions, plus the two rules that keep a customer from being asked
     * twice about the same problem.
     */
    private function refusal(HelpCenterRequest $request, HelpCenterRatingSettings $settings, bool $manual): ?string
    {
        // §20. Answering spam confirms a live address, and a ticket with no valid address has
        // nowhere to send a link.
        if ($request->is_spam) {
            return 'spam';
        }

        $email = trim((string) $request->customer_email);

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'no_customer_email';
        }

        $existing = HelpCenterRating::query()
            ->where('help_center_request_id', $request->id)
            ->get();

        // §16 — one submitted rating per ticket, always.
        if ($existing->contains(fn (HelpCenterRating $r) => $r->isAnswered())) {
            return 'already_rated';
        }

        // A live request already out there. Asking again would put two links in one inbox.
        if ($existing->contains(fn (HelpCenterRating $r) => ! $r->isCancelled() && ! $r->isExpired())) {
            return 'already_requested';
        }

        /*
         * §18 — a ticket resolved a second time does not get a second survey unless the Space
         * asked for one. A manual request is an agent overriding that deliberately.
         */
        if (! $manual && $existing->isNotEmpty() && ! $settings->rerequest_after_reopen) {
            return 'already_requested_once';
        }

        return null;
    }

    /**
     * The ticket came back to life — withdraw anything not yet sent (§6).
     *
     * Only UNSENT requests. A link already in somebody's inbox cannot be recalled, and marking it
     * cancelled would make the page tell a customer their feedback is not wanted when they are
     * looking at it.
     */
    public function cancelPending(HelpCenterRequest $request): int
    {
        return HelpCenterRating::query()
            ->where('help_center_request_id', $request->id)
            ->whereNull('sent_at')
            ->whereNull('submitted_at')
            ->whereNull('cancelled_at')
            ->update(['cancelled_at' => now()]);
    }

    /**
     * The customer answered (§9, §12, §13).
     *
     * Everything the requirement hangs off a submission happens here, in one transaction of
     * thought: the score, the activity row, and the low-rating actions.
     */
    public function submit(HelpCenterRating $rating, int $raw, ?string $comment): HelpCenterRating
    {
        $settings = HelpCenterRatingSettings::for($rating->space);
        $score = $settings->normalise($raw);
        $wasAnswered = $rating->isAnswered();
        $previous = $rating->score;

        $rating->forceFill([
            'raw_score' => $raw,
            'score' => $score,
            'comment' => $comment === null || trim($comment) === '' ? null : trim($comment),
            'submitted_at' => $rating->submitted_at ?? now(),
        ])->save();

        $request = $rating->request;

        if ($request === null) {
            return $rating;
        }

        /*
         * A CHANGE reads differently from a first answer (§23).
         *
         * "changed the rating from 4/5 to 5/5" is the requirement's own wording, and a timeline
         * that recorded it as a second rating would double-count in every report that reads
         * activity rather than the ratings table.
         */
        $this->activity->record(
            $request,
            $wasAnswered
                ? HelpCenterRequestActivity::EVENT_RATING_CHANGED
                : HelpCenterRequestActivity::EVENT_RATING_SUBMITTED,
            'rating',
            $wasAnswered ? $previous.'/5' : null,
            $score.'/5',
            ['comment' => $rating->comment !== null, 'raw' => $raw, 'type' => $rating->rating_type],
        );

        if (! $wasAnswered && $settings->isLow($score)) {
            $this->lowRatingActions($rating, $request, $settings);
        }

        return $rating;
    }

    /**
     * What a low score sets off (§12, §13).
     *
     * Each action is independently switchable and each is wrapped, because these are courtesies
     * around a customer's feedback: the feedback is already saved, and losing it because a tag
     * could not be applied would be the worst possible trade.
     */
    private function lowRatingActions(
        HelpCenterRating $rating,
        HelpCenterRequest $request,
        HelpCenterRatingSettings $settings,
    ): void {
        try {
            if ($settings->low_tag_id !== null) {
                $request->tags()->syncWithoutDetaching([
                    (int) $settings->low_tag_id => ['tenant_id' => $request->tenant_id],
                ]);
            }

            if ($settings->low_internal_note) {
                $this->internalNote($rating, $request, $settings);
            }

            /*
             * Reopening is OFF by default and stays a deliberate choice.
             *
             * A customer who says "this was fine, just slow" has not asked for the ticket back,
             * and a Space that reopens on every 2-star answer will train its agents to dread
             * feedback.
             */
            if ($settings->low_reopen && $request->isClosed()) {
                $open = $request->space?->statuses()
                    ->where('system_key', HelpCenterStatus::SYSTEM_OPEN)->first();

                if ($open !== null) {
                    $request->moveTo($open);
                }
            }
        } catch (\Throwable $e) {
            Log::error('help-center.rating.low_actions_failed', [
                'rating_id' => $rating->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The automatic internal note (§13).
     *
     * Written as an internal note rather than a comment, because it is a message to colleagues
     * about a customer — the one thing P42 is explicit must never reach the customer.
     *
     * NULL author: nobody wrote this, the system did, and attributing it to whoever happened to
     * be assigned would put words in their mouth.
     */
    private function internalNote(
        HelpCenterRating $rating,
        HelpCenterRequest $request,
        HelpCenterRatingSettings $settings,
    ): void {
        $lines = [
            '<p><strong>Customer submitted a '.e($rating->display() ?? $rating->score.'/5')
                .' satisfaction rating.</strong> Follow-up may be required.</p>',
            '<p>Rating: '.e($rating->score.' / 5 — '.$settings->label((int) $rating->score)).'<br />',
            'Customer: '.e($rating->customer?->displayName() ?? $request->customerLabel()).'<br />',
            'Agent: '.e($rating->agent?->displayName() ?? 'Unassigned').'<br />',
            'Rated: '.e(now()->format('M j, Y \a\t g:i A')).'</p>',
        ];

        if ($rating->comment !== null) {
            $lines[] = '<p><em>'.e($rating->comment).'</em></p>';
        }

        \App\Models\HelpCenterRequestNote::create([
            'tenant_id' => $request->tenant_id,
            'help_center_request_id' => $request->id,
            'author_id' => null,
            'content' => implode('', $lines),
            'mentions' => [],
        ]);
    }

    private function settings(HelpCenterRequest $request): ?HelpCenterRatingSettings
    {
        $space = $request->space;

        return $space === null ? null : HelpCenterRatingSettings::for($space);
    }
}
