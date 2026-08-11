<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * work_item_transitions (Activity & Audit spec §10.3) — state movement only.
 *
 * A separate table from activity because it answers a different question: "how did this move
 * through the workflow, and how long did each step take?" (§10.4). Time in state is derived
 * from consecutive timestamps, so nothing needs to be entered by hand.
 *
 * State names are captured alongside the ids (§10.3): states are user-editable and deletable,
 * and a transition history that changes meaning when someone renames a column is not history.
 * Creation writes the FIRST transition (from nothing to the initial state, §10.5), so the
 * time spent in that first state is computable too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_item_transitions', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('work_item_id')->constrained('work_items')->cascadeOnDelete();
            $table->foreignId('from_state_id')->nullable()->constrained('project_item_states')->nullOnDelete();
            $table->foreignId('to_state_id')->nullable()->constrained('project_item_states')->nullOnDelete();
            $table->string('from_state_name')->nullable();
            $table->string('to_state_name')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('transitioned_at');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['work_item_id', 'transitioned_at']); // §23
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_item_transitions');
    }
};
