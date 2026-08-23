<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snoozing a Request (docs/features/help-center.md, P45).
 *
 * Columns on `help_center_requests` rather than a `help_center_request_snoozes` table, because a
 * Request has exactly ONE snooze — the state it is in right now. A table would model a history
 * of snoozes, and this module already has one of those: `help_center_request_activity`, which
 * the requirement asks these events to appear in anyway. Two records of the same event are two
 * records free to disagree.
 *
 * The requirement's five stored facts map one to one:
 *
 *   snooze date and time   → snoozed_until
 *   snooze condition       → snooze_condition
 *   who snoozed it         → snoozed_by_id
 *   when it was snoozed    → snoozed_at
 *   snooze history         → help_center_request_activity, as `snoozed` / `unsnoozed` rows
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('help_center_requests', function (Blueprint $table) {
            /*
             * When it comes back. NULL means not snoozed, and it is the ONLY thing that means
             * that — the other three columns are description, not state.
             *
             * Indexed together with the Space, because the two reads this column has are "which
             * of this Space's Requests are snoozed?" (the queue) and "which are due?" (the
             * sweep), and both of them run often enough to care.
             */
            $table->timestamp('snoozed_until')->nullable()->after('closed_at');

            // `if_no_reply` or `regardless` — see config('help-center.snooze_conditions').
            $table->string('snooze_condition', 20)->nullable()->after('snoozed_until');

            // Who did it. NULLABLE and nullOnDelete for the same reason the activity actor is:
            // a person can leave, and their leaving must not take the ticket's snooze with them.
            $table->foreignId('snoozed_by_id')->nullable()->after('snooze_condition')
                ->constrained('users')->nullOnDelete();

            // When the snooze was set — NOT the same as `snoozed_until`. "Snoozed 3 days ago
            // until tomorrow" needs both, and neither can be derived from the other.
            $table->timestamp('snoozed_at')->nullable()->after('snoozed_by_id');

            $table->index(['help_center_space_id', 'snoozed_until'], 'hc_requests_space_snoozed');
        });
    }

    public function down(): void
    {
        Schema::table('help_center_requests', function (Blueprint $table) {
            $table->dropIndex('hc_requests_space_snoozed');
            $table->dropConstrainedForeignId('snoozed_by_id');
            $table->dropColumn(['snoozed_until', 'snooze_condition', 'snoozed_at']);
        });
    }
};
