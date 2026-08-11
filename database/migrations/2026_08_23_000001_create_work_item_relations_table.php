<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * work_item_relations (Collaboration spec §27–§36, §52) — dependencies and relations between
 * two work items.
 *
 * TENANT-SCOPED (CLAUDE.md §7) and project-stamped. Both sides of a relation must live in the
 * same workspace: a cross-workspace relation would make one tenant's work items reachable
 * from another's detail panel, which §56 forbids outright.
 *
 * ONE ROW PER RELATIONSHIP, stored in its canonical direction (§52). "Blocked by" is not a
 * stored type — it is what a `blocking` row looks like from the other end, and the service
 * layer derives it. Storing both directions would let the two halves drift apart, and
 * removing one side would have to remember to remove the other.
 *
 * Parent/child is deliberately NOT here: `work_items.parent_id` already models it and is what
 * the list, the create modal and the detail panel all read. Two sources of truth for the same
 * fact is how they end up disagreeing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_item_relations', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('work_item_id')->constrained('work_items')->cascadeOnDelete();
            $table->foreignId('related_work_item_id')->constrained('work_items')->cascadeOnDelete();
            // blocking | related | duplicate_of  (blocked_by is derived from blocking)
            $table->string('relation_type', 20);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            // The same pair cannot carry the same relation twice (§31, §36).
            $table->unique(['work_item_id', 'related_work_item_id', 'relation_type'], 'work_item_relations_pair_unique');
            // Reading one item's panel looks it up from both ends.
            $table->index(['related_work_item_id', 'relation_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_item_relations');
    }
};
