<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Escalation rules (docs/features/helpdesk-sla.md, §24–§25). TENANT-SCOPED.
 *
 * Space-level, not policy-level: §24 puts them at **Space → Settings → SLA → Escalation Rules**,
 * beside the policies rather than inside one. `help_center_sla_policy_id` narrows a rule to a
 * single policy when a team wants that, and null means "every policy in this Space".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_center_sla_escalations', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->foreignId('help_center_space_id')->constrained('help_center_spaces')->cascadeOnDelete();

            $table->foreignId('help_center_sla_policy_id')->nullable()
                ->constrained('help_center_sla_policies')->cascadeOnDelete();

            $table->string('name', 120);

            /*
             * One of `help-center.sla_escalation_triggers` — `percent_50`, `percent_75`,
             * `percent_90`, `due_soon`, `breached` (§24).
             *
             * The requirement's last three triggers ("First Response SLA Breached", …) are this
             * column set to `breached` plus `kind` naming the stage, rather than three more
             * trigger values. Otherwise "SLA reaches 90%" would need its own three as well, and
             * the list would be fifteen entries expressing five ideas.
             */
            $table->string('trigger', 30);

            // Which clock the trigger watches. Null is any of them.
            $table->string('kind', 20)->nullable();

            /*
             * `[{"action":"add_tag","value":"SLA Risk"}, …]` (§25).
             *
             * JSON for the same reason a policy's conditions are (SLA-D5): the action list is
             * authored, saved and executed as one ordered group, and nothing ever points at a
             * single action.
             */
            $table->json('actions');

            $table->boolean('is_active')->default(true);
            $table->integer('position')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['help_center_space_id', 'position'], 'hc_sla_esc_space_position_index');
            $table->index(['help_center_space_id', 'trigger'], 'hc_sla_esc_space_trigger_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('help_center_sla_escalations');
    }
};
