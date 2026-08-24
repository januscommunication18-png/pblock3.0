<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The per-priority promise (docs/features/helpdesk-sla.md, §8). TENANT-SCOPED.
 *
 * One row per policy per priority, and the priority keys are the product's existing ones
 * (SLA-D2) — `urgent`, `high`, `normal` (shown as Medium), `low`, `none`.
 *
 * Each stage is a VALUE and a UNIT, not a minute count (SLA-D7). "2 Business Days" under an
 * 08:00–18:00 calendar is 20 business hours, and it stops being 20 the moment somebody edits the
 * calendar — so the conversion belongs to the engine at evaluation time, not to this row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_center_sla_targets', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->foreignId('help_center_sla_policy_id')
                ->constrained('help_center_sla_policies')->cascadeOnDelete();

            $table->string('priority', 20);

            /*
             * NULL is "this policy makes no promise about this stage".
             *
             * Not zero, which would mean "immediately" and breach on creation. A policy that
             * promises a first response and says nothing about resolution is a real policy, and
             * a timer is only started for the stages that carry a target.
             */
            foreach (['first_response', 'next_response', 'resolution'] as $stage) {
                $table->unsignedInteger($stage.'_value')->nullable();
                $table->string($stage.'_unit', 10)->nullable();
            }

            $table->timestamps();

            $table->unique(['help_center_sla_policy_id', 'priority'], 'hc_sla_target_policy_priority_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('help_center_sla_targets');
    }
};
