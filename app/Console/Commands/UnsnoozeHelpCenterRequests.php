<?php

namespace App\Console\Commands;

use App\Models\HelpCenterRequest;
use App\Services\HelpCenter\SnoozeManager;
use Illuminate\Console\Command;

/**
 * Bring back every Request whose snooze has run out (docs/features/help-center.md, P45).
 *
 * Scheduled every minute (routes/console.php). What it does NOT do is decide which queue a
 * Request belongs in: the model's `snoozed` scope is `snoozed_until > now`, so a Request whose
 * time has passed is already back in its queue on every screen before this ever runs.
 *
 * That is the whole design. This command exists to write the activity row the requirement asks
 * for and to clear the columns — not to make the Inbox correct. A queue that depends on a cron
 * job to be truthful lies every time the cron job misses a beat, and support software that
 * forgets a ticket because a worker was down is support software nobody can trust.
 */
class UnsnoozeHelpCenterRequests extends Command
{
    protected $signature = 'help-center:unsnooze {--limit=200 : How many to wake in one pass}';

    protected $description = 'Return Help Center Requests whose snooze period has ended';

    public function handle(SnoozeManager $snooze): int
    {
        /*
         * `withoutTenancy` is NOT used, and the model's tenant scope is NOT bypassed.
         *
         * The scope is keyed on the current tenant, and a scheduled command has none — so this
         * would silently wake nothing. `withoutGlobalScope` is the honest way to say "every
         * workspace", and it is one of CLAUDE.md §7's "explicit, reviewed admin paths": a
         * cross-tenant read whose whole job is cross-tenant, with no request and no user behind
         * it. Nothing tenant-specific is written — the manager only ever writes the row it was
         * handed, and each row carries its own tenant_id.
         */
        $due = HelpCenterRequest::query()
            ->withoutGlobalScopes()
            ->whereNotNull('snoozed_until')
            ->where('snoozed_until', '<=', now())
            ->orderBy('snoozed_until')
            ->limit((int) $this->option('limit'))
            ->get();

        foreach ($due as $request) {
            $snooze->unsnooze($request, SnoozeManager::REASON_DUE);
        }

        if ($due->isNotEmpty()) {
            $this->info($due->count().' Request'.($due->count() === 1 ? '' : 's').' unsnoozed.');
        }

        return self::SUCCESS;
    }
}
