<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Business SLA policy (docs/features/helpdesk-sla.md, §3–§4, §12–§14, §17, §23). TENANT-SCOPED.
 *
 * The policy IS the assignment rule (SLA-D5). §12 gives each policy one "SLA Applies When" block
 * and §14 orders policies — so `conditions`, `match_type` and `position` live here rather than in
 * a rules table that would hold exactly one row per policy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_center_sla_policies', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->foreignId('help_center_space_id')->constrained('help_center_spaces')->cascadeOnDelete();

            $table->string('name', 120);
            $table->text('description')->nullable();

            // Inactive, not deleted (§3). A policy a team has paused for the quarter is a policy
            // they mean to bring back, and its targets and history come back with it.
            $table->boolean('is_active')->default(true);

            // The fallback when nothing matches (§4, §13). One per Space, enforced by the writer
            // for the same reason `is_default` on business hours is.
            $table->boolean('is_default')->default(false);

            /*
             * Null means 24/7 (SLA-D3).
             *
             * `nullOnDelete` rather than cascade: deleting a calendar must not delete the
             * commitments made against it. The policy falls back to round-the-clock counting,
             * which is the STRICTER reading — a deleted calendar cannot quietly buy time.
             */
            $table->foreignId('help_center_business_hours_id')->nullable()
                ->constrained('help_center_business_hours')->nullOnDelete();

            // Where "Due Soon" starts, as a percentage of the target (§17).
            $table->unsignedTinyInteger('warning_percent')->default(80);

            // How the conditions combine — `all` or `any` (§12's "AND" example).
            $table->string('match_type', 3)->default('all');

            /*
             * `[{"field":"company","operator":"is","value":[12]}, …]` (§12).
             *
             * An EMPTY array is a policy that matches nothing by condition — it can still be the
             * Default. That is the honest reading of "no conditions": a policy with no rule has
             * not said when it applies, and treating empty as "matches everything" would make
             * the first unconfigured policy swallow every ticket in the Space.
             */
            $table->json('conditions')->nullable();

            // `resume` | `restart` | `none` (§23). Default is the requirement's recommendation.
            $table->string('reopen_behavior', 20)->default('resume');

            // Evaluation order; first match wins (§14). Drag-and-drop writes this.
            $table->integer('position')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['help_center_space_id', 'position'], 'hc_sla_policy_space_position_index');
            $table->index(['help_center_space_id', 'is_default'], 'hc_sla_policy_space_default_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('help_center_sla_policies');
    }
};
