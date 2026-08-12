<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * project_views (Views §22.1) — a saved, configurable spreadsheet-style view of project data.
 *
 * TENANT-SCOPED (CLAUDE.md §7) and project-scoped: a View belongs to exactly one project, and
 * §5 lists Views per project.
 *
 * `dataset_type` is here from the start even though Phase 1 only supports work items (§6.2).
 * §30 asks for ONE reusable View Engine whose primary row can later be an Epic, Module or
 * Cycle, and a column added later would mean backfilling every existing row — cheap now,
 * awkward then.
 *
 * `softDeletes` matches §4.2: switching the View feature off must leave existing Views stored
 * and restore them on re-enable, so nothing here is ever destroyed by a feature toggle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_views', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('dataset_type', 32)->default('work_items');
            // §6.3: private (owner only) or project (permitted project members).
            $table->string('visibility', 16)->default('private');
            // §5.2 shows the owner. nullOnDelete rather than cascade: a departing user must
            // not take a shared project View with them — ownership is transferable (§14.4).
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            // §12.5 — compact / standard / comfortable, persisted with the View like §13 asks.
            $table->string('density', 16)->default('standard');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            // Drives §5's listing: a project's Views, most recently modified first, with the
            // visibility split that decides which of them the caller may see.
            $table->index(['project_id', 'visibility', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_views');
    }
};
