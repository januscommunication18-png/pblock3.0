<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which System Category a workflow status belongs to (docs/features/help-center.md, P54).
 *
 * P53 added the five fixed categories as a vocabulary — Open, Active, Waiting, Resolved, Closed —
 * and said plainly that nothing yet mapped a status to one. This is that mapping.
 *
 * It is what lets two Spaces run entirely different workflows and still be reported on together:
 * one Space's `Inprogress` and another's `With Engineering` are both `active`, and a query that
 * wants "everything being worked on" has something stable to ask for. Without it, cross-Space
 * reporting can only group by status NAME, which is to say it cannot group at all.
 *
 * NOT NULL with a default. A status without a category would be a row every consumer has to
 * write a fallback for, and five fallbacks in five files is how the sixth one gets forgotten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('help_center_statuses', function (Blueprint $table) {
            // The keys of config('help-center.system_categories'). A string rather than an enum
            // for the reason the rest of this module uses strings: an enum is a migration every
            // time the vocabulary changes, and the vocabulary lives in config.
            $table->string('system_category', 20)->default('active')->after('system_key');
        });

        /*
         * BACKFILL, derived from what each status already says about itself.
         *
         * Deliberately not "everything becomes active": these Spaces have real workflows and a
         * uniform backfill would make the mapping wrong on its first day, which is worse than
         * not having one — a wrong category is a report that is confidently incorrect.
         *
         * The order matters. `system_key` is the strongest signal because it is the one thing a
         * Space cannot change; the waiting clock is the next best evidence of what a state means.
         *
         *   closed          → closed     (the workflow's own end)
         *   open            → open       (the workflow's own start)
         *   waiting on the customer → waiting
         *   waiting on nobody       → resolved  (answered, clock stopped, not shut)
         *   everything else         → active    (an agent owes something)
         *
         * Each is a separate UPDATE rather than one CASE expression: they are five rules, and
         * five statements that can be read one at a time beat one statement nobody re-reads.
         */
        DB::table('help_center_statuses')->where('system_key', 'closed')->update(['system_category' => 'closed']);
        DB::table('help_center_statuses')->where('system_key', 'open')->update(['system_category' => 'open']);

        DB::table('help_center_statuses')
            ->whereNull('system_key')
            ->where('waiting_on', 'customer')
            ->update(['system_category' => 'waiting']);

        DB::table('help_center_statuses')
            ->whereNull('system_key')
            ->where('waiting_on', 'neither')
            ->update(['system_category' => 'resolved']);
    }

    public function down(): void
    {
        Schema::table('help_center_statuses', function (Blueprint $table) {
            $table->dropColumn('system_category');
        });
    }
};
