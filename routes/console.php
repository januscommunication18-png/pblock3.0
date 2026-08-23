<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Snoozed Requests come back (docs/features/help-center.md, P45).
 *
 * Every minute, because the snooze times an agent picks are wall-clock times — "8 am" that fires
 * at 8:14 is a broken promise. `withoutOverlapping` because a slow pass must not have a second
 * one waking the same rows behind it.
 *
 * The queues do not depend on this running; see the command for why.
 */
Schedule::command('help-center:unsnooze')->everyMinute()->withoutOverlapping();

/*
 * Rating requests and their reminders (docs/features/help-center.md, P56).
 *
 * Every minute, because a "send immediately" request that waits up to an hour is not immediate.
 * `withoutOverlapping` so a slow pass never has a second one sending the same rows behind it.
 */
Schedule::command('help-center:ratings')->everyMinute()->withoutOverlapping();
