<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Ticket SLA Instance (docs/features/helpdesk-sla.md, §37). TENANT-SCOPED.
 *
 * One row per Request: which policy applies, when it was applied and by whom. The clocks
 * themselves are `help_center_sla_timers` — a ticket has an unbounded number of Next Response
 * cycles (§10), so they cannot be columns here.
 *
 * The policy's timezone and warning threshold are SNAPSHOT on this row. A ticket's deadline was
 * calculated under the settings that existed when it arrived; editing the policy tomorrow must
 * not silently reinterpret yesterday's breach.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_center_ticket_slas', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();

            // One instance per Request — the unique is the rule, not a convention the writer
            // remembers. A ticket under two SLAs has two sets of deadlines and no answer.
            $table->foreignId('help_center_request_id')
                ->constrained('help_center_requests')->cascadeOnDelete();

            /*
             * Nullable, and `nullOnDelete`: a deleted policy must not delete the ticket's SLA
             * history. The timers keep their targets and their outcomes; only the name of what
             * promised them is gone, which the panel renders as the snapshot below.
             */
            $table->foreignId('help_center_sla_policy_id')->nullable()
                ->constrained('help_center_sla_policies')->nullOnDelete();

            // What the policy was called when it was applied — so a deleted or renamed policy
            // still reads correctly in the ticket's history (§30).
            $table->string('policy_name', 120)->nullable();

            $table->timestamp('applied_at')->nullable();

            // Null when evaluation applied it; set when an administrator changed it by hand
            // (§31). The distinction is the whole audit value of the column.
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('timezone', 64)->default('UTC');
            $table->unsignedTinyInteger('warning_percent')->default(80);

            $table->timestamps();

            $table->unique('help_center_request_id', 'hc_ticket_sla_request_unique');
            $table->index(['help_center_sla_policy_id'], 'hc_ticket_sla_policy_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('help_center_ticket_slas');
    }
};
