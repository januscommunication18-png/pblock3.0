<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What has already been escalated (docs/features/helpdesk-sla.md, SLA-D11). TENANT-SCOPED.
 *
 * The ledger that makes escalation fire ONCE. The sweep that detects "90% consumed" (SLA-D10)
 * runs on a schedule, so it will see the same over-threshold timer on every tick until the clock
 * stops — without this, "Notify Team Lead" is a page every minute.
 *
 * Written inside the same transaction that performs the actions, so a crash between the two
 * leaves the rule un-fired rather than recorded-but-not-done.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_center_sla_escalation_runs', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();

            /*
             * The constraints are NAMED, here and below.
             *
             * Laravel's generated name is `<table>_<column>_foreign`, and this table's name plus
             * this column's is 69 characters — past MySQL's 64-character identifier limit. The
             * migration fails at `alter table`, after the table exists, which is the worst place
             * for a schema change to stop.
             */
            $table->foreignId('help_center_sla_escalation_id')
                ->constrained('help_center_sla_escalations', indexName: 'hc_sla_esc_run_escalation_foreign')
                ->cascadeOnDelete();

            // The TIMER, not the ticket: a rule that fires on "Next Response 90%" must be able to
            // fire again on the next cycle, and each cycle is its own timer row.
            $table->foreignId('help_center_sla_timer_id')
                ->constrained('help_center_sla_timers', indexName: 'hc_sla_esc_run_timer_foreign')
                ->cascadeOnDelete();

            $table->timestamp('fired_at');

            // What the actions did, for the ticket's history and for a support answer to "why did
            // this get reassigned?". Includes failures — an action that could not run is exactly
            // what somebody will be asking about.
            $table->json('result')->nullable();

            $table->timestamps();

            $table->unique(
                ['help_center_sla_escalation_id', 'help_center_sla_timer_id'],
                'hc_sla_esc_run_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('help_center_sla_escalation_runs');
    }
};
