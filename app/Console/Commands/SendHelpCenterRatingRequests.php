<?php

namespace App\Console\Commands;

use App\Models\HelpCenterRating;
use App\Models\HelpCenterEmailTemplate;
use App\Models\HelpCenterRatingSettings;
use App\Services\HelpCenter\EmailTemplateRenderer;
use App\Services\HelpCenter\RatingSender;
use Illuminate\Console\Command;

/**
 * Send the rating requests that have come due, and their reminders (P56 §6, §14).
 *
 * Scheduled every minute alongside the unsnooze sweep. Both exist for the same reason: something
 * has to happen at a time nobody is looking at the screen.
 *
 * Unlike the unsnooze sweep, this one is LOAD-BEARING — a request that is never sent is a
 * customer who is never asked, and no screen can compensate for that later. That is worth
 * stating because the two commands look alike and only one of them is safe to miss.
 */
class SendHelpCenterRatingRequests extends Command
{
    protected $signature = 'help-center:ratings {--limit=100 : How many to send in one pass}';

    protected $description = 'Send due Help Center rating requests and reminders';

    public function handle(RatingSender $sender): int
    {
        $limit = (int) $this->option('limit');

        $sent = $this->sendDue($sender, $limit);
        $reminded = $this->sendReminders($sender, $limit);

        if ($sent || $reminded) {
            $this->info($sent.' sent, '.$reminded.' reminded.');
        }

        return self::SUCCESS;
    }

    /**
     * First sends.
     *
     * `withoutGlobalScopes` for the reason the unsnooze sweep uses it: a scheduled command has no
     * tenant, so the tenant scope would silently match nothing. Each row carries its own
     * `tenant_id` and nothing here writes across one.
     */
    private function sendDue(RatingSender $sender, int $limit): int
    {
        $due = HelpCenterRating::query()
            ->withoutGlobalScopes()
            ->due()
            ->with(['request', 'space.inboxes.emailAddresses', 'agent'])
            ->orderBy('send_after')
            ->limit($limit)
            ->get();

        $count = 0;

        foreach ($due as $rating) {
            /*
             * Re-checked at SEND time, not only at request time.
             *
             * A ticket can be marked spam, or its Space can switch rating off, in the hour
             * between the request being raised and its delay elapsing. Sending anyway would be
             * honouring a decision that has since been reversed.
             */
            if ($rating->request?->is_spam) {
                $rating->forceFill(['cancelled_at' => now()])->save();

                continue;
            }

            if ($rating->space === null || ! HelpCenterRatingSettings::for($rating->space)->enabled) {
                continue;
            }

            /*
             * The EMAIL TEMPLATE can be switched off independently of the feature (P60).
             *
             * Skipped, not cancelled. Disabling the template is "stop sending these for now", not
             * "throw away the requests already raised" — switching it back on should let them go,
             * and a cancelled row can never be un-cancelled.
             */
            if (! app(EmailTemplateRenderer::class)
                ->resolve($rating->space, HelpCenterEmailTemplate::TYPE_RATING_REQUEST)['enabled']) {
                continue;
            }

            if ($sender->send($rating)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Reminders (§14).
     *
     * "A reminder must not be sent after the customer submits a rating" — which the `whereNull`
     * on `submitted_at` guarantees rather than a check somebody has to remember.
     */
    private function sendReminders(RatingSender $sender, int $limit): int
    {
        $candidates = HelpCenterRating::query()
            ->withoutGlobalScopes()
            ->whereNotNull('sent_at')
            ->whereNull('submitted_at')
            ->whereNull('cancelled_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->with(['request', 'space.inboxes.emailAddresses', 'agent'])
            ->limit($limit)
            ->get();

        $count = 0;

        foreach ($candidates as $rating) {
            if ($rating->space === null) {
                continue;
            }

            $settings = HelpCenterRatingSettings::for($rating->space);

            if (! $settings->enabled || ! $settings->reminder_enabled) {
                continue;
            }

            if ((int) $rating->reminders_sent >= (int) $settings->reminder_max) {
                continue;
            }

            /*
             * The clock runs from the LAST thing we sent, not from the first.
             *
             * Two reminders two days apart is a nudge; two reminders on the same afternoon
             * because both were measured from the original send is harassment.
             */
            $since = $rating->reminders_sent > 0 ? $rating->updated_at : $rating->sent_at;

            if ($since === null || $since->addDays((int) $settings->reminder_after_days)->isFuture()) {
                continue;
            }

            if ($sender->send($rating, reminder: true)) {
                $count++;
            }
        }

        return $count;
    }
}
