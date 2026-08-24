<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a status does to the SLA clock (docs/features/helpdesk-sla.md, §19).
 *
 * A COLUMN on the status and not a mapping from `system_category` (SLA-D8). §19 draws the
 * control on the status row, and although its example table happens to follow the categories —
 * Waiting pauses, Resolved completes, Closed stops — a team may legitimately want an Active
 * status that pauses ("With Engineering", where nothing is owed to the customer). The category
 * supplies the DEFAULT, once, in the backfill below; after that the two are independent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('help_center_statuses', function (Blueprint $table) {
            // `continue` | `pause` | `complete_resolution` | `stop` — the keys of
            // config('help-center.sla_behaviors'). A string, for the reason `system_category`
            // is one: the vocabulary lives in config, not in a schema change.
            $table->string('sla_behavior', 30)->default('continue')->after('system_category');
        });

        /*
         * BACKFILL from the category, which is exactly §19's table.
         *
         * `continue` is the column default, so `open` and `active` need no statement — they are
         * already right. Writing them anyway would be three UPDATEs where one of them says
         * nothing, and the silence here is the point: only the categories that CHANGE the clock
         * are named.
         */
        DB::table('help_center_statuses')->where('system_category', 'waiting')
            ->update(['sla_behavior' => 'pause']);

        DB::table('help_center_statuses')->where('system_category', 'resolved')
            ->update(['sla_behavior' => 'complete_resolution']);

        DB::table('help_center_statuses')->where('system_category', 'closed')
            ->update(['sla_behavior' => 'stop']);
    }

    public function down(): void
    {
        Schema::table('help_center_statuses', function (Blueprint $table) {
            $table->dropColumn('sla_behavior');
        });
    }
};
