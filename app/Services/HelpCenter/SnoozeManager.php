<?php

namespace App\Services\HelpCenter;

use App\Models\HelpCenterRequest;
use App\Models\HelpCenterRequestActivity;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;

/**
 * Putting a Request to sleep and waking it up (docs/features/help-center.md, P45).
 *
 * ONE place that writes the four snooze columns, for the same reason `RequestActivity` is one
 * place that writes history: a snooze can end four different ways — the time arriving, an agent
 * unsnoozing by hand, the customer replying, or the ticket being closed — and each of those
 * lives in a different file. Four files each clearing the columns their own way is four chances
 * to clear three of them and leave the fourth, which is a Request that is neither snoozed nor
 * not.
 *
 * Everything here also writes the activity row, because the requirement asks for these events in
 * Activity and History and a state change recorded in one place and not the other is worse than
 * one recorded nowhere — it looks complete.
 */
class SnoozeManager
{
    /** The customer wrote back, and the snooze said that should cut it short. */
    public const REASON_REPLY = 'customer_reply';

    /** The clock ran out. */
    public const REASON_DUE = 'due';

    /** An agent woke it by hand. */
    public const REASON_MANUAL = 'manual';

    /** It was closed or marked spam, which ends a snooze whatever its condition said. */
    public const REASON_SUPERSEDED = 'superseded';

    public function __construct(private readonly RequestActivity $activity) {}

    /** The conditions a snooze may carry, as configured. */
    public static function conditions(): array
    {
        return (array) config('help-center.snooze_conditions');
    }

    public static function isCondition(?string $key): bool
    {
        return $key !== null && array_key_exists($key, self::conditions());
    }

    public static function conditionLabel(?string $key): ?string
    {
        return $key === null ? null : (self::conditions()[$key]['label'] ?? $key);
    }

    /**
     * Snooze a Request until `$until` (P45).
     *
     * Re-snoozing an already-snoozed Request is allowed and is how "change the snooze time" and
     * "change the snooze condition" are done — the requirement lists both as separate permissions
     * but they are the same write, and a second verb here would be two code paths that must agree
     * about what a snooze is.
     *
     * `snoozed_at` is only stamped when the Request was NOT already asleep. Editing the time of a
     * running snooze does not restart when it began, and overwriting it would lose the answer to
     * "how long has this been parked?".
     */
    public function snooze(
        HelpCenterRequest $request,
        CarbonInterface $until,
        string $condition,
    ): HelpCenterRequest {
        $wasSnoozed = $request->isSnoozed();
        $previous = $request->snoozed_until;

        $request->forceFill([
            'snoozed_until' => $until,
            'snooze_condition' => $condition,
            'snoozed_by_id' => Auth::id(),
            'snoozed_at' => $wasSnoozed ? ($request->snoozed_at ?? now()) : now(),
            'last_activity_at' => now(),
        ])->save();

        /*
         * The waiting clock is NOT touched.
         *
         * A snoozed ticket the customer is still owed a reply on is still owed a reply — parking
         * it is the agent's decision about their own queue, not a statement about who the
         * conversation is waiting on. Resetting the clock here would make a week-old unanswered
         * ticket look freshly handled the moment somebody snoozed it, which is the one thing an
         * Inbox sorted by longest wait must never let happen (P9, Waiting Period).
         */

        $this->activity->record(
            $request,
            HelpCenterRequestActivity::EVENT_SNOOZED,
            'snooze',
            $wasSnoozed ? $previous?->format('M j, Y \a\t g:i A') : null,
            $until->format('M j, Y \a\t g:i A'),
            [
                'until' => $until->toIso8601String(),
                'condition' => $condition,
                'condition_label' => self::conditionLabel($condition),
                'rescheduled' => $wasSnoozed,
            ],
        );

        return $request;
    }

    /**
     * Wake a Request up, whatever woke it.
     *
     * Returns false when it was not asleep, so every caller can be written as "end any snooze"
     * without first asking whether there is one — the reply path and the close path both want
     * that, and neither should have to know.
     *
     * The requirement's "preserve its existing assignee" and "preserve its status" are honoured
     * by NOT being implemented: nothing here writes either column. A snooze was never a change
     * to them, so ending one is not a change back.
     */
    public function unsnooze(HelpCenterRequest $request, string $reason): bool
    {
        if ($request->snoozed_until === null) {
            return false;
        }

        $until = $request->snoozed_until;
        $condition = $request->snooze_condition;

        $request->forceFill([
            'snoozed_until' => null,
            'snooze_condition' => null,
            'snoozed_by_id' => null,
            'snoozed_at' => null,
            'last_activity_at' => now(),
        ])->save();

        /*
         * A snooze that is being SUPERSEDED writes no row.
         *
         * Closing a snoozed ticket already records "status changed to Closed"; adding "and its
         * snooze ended" next to it is describing the same act twice, and a history that
         * double-reports is a history people learn to skim.
         */
        if ($reason === self::REASON_SUPERSEDED) {
            return true;
        }

        $this->activity->record(
            $request,
            HelpCenterRequestActivity::EVENT_UNSNOOZED,
            'snooze',
            $until->format('M j, Y \a\t g:i A'),
            null,
            [
                'reason' => $reason,
                'was_until' => $until->toIso8601String(),
                'condition' => $condition,
                'condition_label' => self::conditionLabel($condition),
            ],
        );

        return true;
    }

    /**
     * The customer replied — end the snooze IF the condition said it should (P45).
     *
     * The whole difference between the two conditions is this method. `regardless` returns false
     * and the reply is simply stored, which is the requirement's "the new customer reply should
     * still be stored on the ticket and visible when the ticket is reopened" — it is stored the
     * same way every reply is, because nothing here stops it.
     */
    public function customerReplied(HelpCenterRequest $request): bool
    {
        if (! $request->isSnoozed() || $request->snooze_condition !== 'if_no_reply') {
            return false;
        }

        return $this->unsnooze($request, self::REASON_REPLY);
    }
}
