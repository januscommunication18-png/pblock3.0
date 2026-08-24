<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One SLA clock (docs/features/helpdesk-sla.md, §9–§11, §16, §18). TENANT-SCOPED.
 *
 * Rows and not columns on the instance, because Next Response repeats: every customer reply
 * opens a new cycle (§10), and a ticket with nine rounds of correspondence has nine of them.
 * `cycle` numbers them; First Response and Resolution never exceed cycle 1.
 *
 * `due_at` is a WALL-CLOCK instant even though the budget is counted in business minutes
 * (SLA-D9). The Inbox sorts by "closest to breaching" (§29) and filters on it (§28); a sort that
 * has to run a business-hours calculation per row cannot be done in SQL. It is recalculated
 * whenever the clock pauses, resumes or is retargeted — those are the only moments it can move.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_center_sla_timers', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->foreignId('help_center_ticket_sla_id')
                ->constrained('help_center_ticket_slas')->cascadeOnDelete();

            // Denormalized from the instance so the Inbox can join timers to tickets in one hop.
            // The Inbox reads this table for every visible row; a second join for the id it
            // already has would be paid on every keystroke of the filter.
            $table->foreignId('help_center_request_id')
                ->constrained('help_center_requests')->cascadeOnDelete();

            // `first_response` | `next_response` | `resolution`.
            $table->string('kind', 20);

            // 1-based. Only `next_response` ever goes past 1.
            $table->unsignedInteger('cycle')->default(1);

            // §16's six states: not_started, running, due_soon, paused, completed, breached.
            $table->string('status', 20)->default('not_started');

            /*
             * The budget, resolved to business minutes when the clock STARTED.
             *
             * Resolved once and kept: the target is `2 days` on a calendar, and re-resolving it
             * on every read would move a running deadline whenever somebody edited the working
             * week. The authored value and unit are kept beside it so the panel can say "2
             * Business Days" rather than "1200 minutes".
             */
            $table->unsignedInteger('target_minutes')->nullable();
            $table->unsignedInteger('target_value')->nullable();
            $table->string('target_unit', 10)->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('due_at')->nullable();

            // When the current pause began, and the business minutes already banked by earlier
            // pauses. Both are needed: the first is a live state, the second is history.
            $table->timestamp('paused_at')->nullable();
            $table->unsignedInteger('paused_minutes')->default(0);

            $table->timestamp('completed_at')->nullable();
            $table->timestamp('breached_at')->nullable();

            // §18's "Time Over SLA", frozen at breach. Computed later from `due_at` and "now"
            // would keep growing after the ticket was resolved.
            $table->unsignedInteger('over_minutes')->nullable();

            // Business minutes actually consumed, written when the clock stops. §32's average
            // response and resolution times read this rather than subtracting timestamps, which
            // would count the nights and weekends the SLA never charged for.
            $table->unsignedInteger('elapsed_minutes')->nullable();

            $table->timestamps();

            // One clock per stage per cycle. The engine is re-entrant — a webhook redelivery
            // must not open a second First Response — and this is where that is guaranteed.
            $table->unique(
                ['help_center_ticket_sla_id', 'kind', 'cycle'],
                'hc_sla_timer_instance_kind_cycle_unique'
            );

            // The sweep (SLA-D10) asks one question: which running clocks are past due? And the
            // Inbox asks: what is this ticket's most urgent one?
            $table->index(['status', 'due_at'], 'hc_sla_timer_status_due_index');
            $table->index(['help_center_request_id', 'status'], 'hc_sla_timer_request_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('help_center_sla_timers');
    }
};
