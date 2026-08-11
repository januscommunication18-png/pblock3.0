<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * work_item_activity (Work Items §6 / §8) — the audit feed behind the detail view's
 * All / Activity / Transition / History tabs.
 *
 * One table serves all four, because they are different reads of the same events:
 *   • Activity  — every row, rendered as a sentence.
 *   • Transition — rows where `field = 'state'` (from-state → to-state, actor, timestamp).
 *   • History   — rows with `old_value` / `new_value`, i.e. property-level before/after.
 * Comments get their own table when they land; this one is system-generated events only.
 *
 * TENANT-SCOPED (CLAUDE.md §7) and cascaded from the work item, so deleting an item takes
 * its history with it. `actor_id` is nullable: it survives the user being deleted, and
 * leaves room for system-generated events with no human actor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_item_activity', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('work_item_id')->constrained('work_items')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 40);
            // Which property changed, plus its before/after. Null for whole-item events
            // such as `created`. Stored as text so arrays (assignees, labels) can be JSON.
            $table->string('field', 40)->nullable();
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            // Display context resolved at write time (names, colors) so the feed stays
            // readable after a state or label is renamed or deleted.
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            // The feed is always read newest-first for one work item.
            $table->index(['work_item_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_item_activity');
    }
};
