<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * work_items (Phase 5, Work Items §8) — the unit of work tracked inside a project.
 *
 * TENANT-SCOPED (CLAUDE.md §7) *and* project-scoped: every row carries `tenant_id` for the
 * BelongsToTenant global scope plus `project_id`, so a work item can never be read outside
 * its workspace or leak between projects (requirements §7).
 *
 * `identifier` is the human-facing ID and is stored denormalized so lists and copy-link can
 * render it without a join. It was originally `<PROJECT>-<n>`; the
 * 2026_08_19_000002 migration replaced that with a workspace-unique number.
 * Cycle/module associations are deliberately absent — those tabs are Coming Soon.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_items', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->unsignedInteger('sequence_no');
            $table->string('identifier', 24);
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('state_id')->nullable()->constrained('project_item_states')->nullOnDelete();
            $table->string('priority', 10)->default('none');
            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable();
            $table->foreignId('parent_id')->nullable()->constrained('work_items')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            // The sequence and the rendered ID are both unique per project (WI-006).
            $table->unique(['project_id', 'sequence_no']);
            $table->unique(['project_id', 'identifier']);
            // Drives the default list: a project's live items grouped by state.
            $table->index(['project_id', 'archived_at', 'state_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_items');
    }
};
