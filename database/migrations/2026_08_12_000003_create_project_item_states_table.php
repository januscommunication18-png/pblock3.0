<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * project_item_states (Phase 4 / PRJ-043) — project-scoped work-item states, distinct from
 * the workspace-wide project_states used in Workspace Settings. TENANT-SCOPED + project_id.
 * Seeded with a default set when a project is created.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_item_states', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('name');
            $table->string('color', 7);
            $table->string('description')->nullable();
            $table->string('group', 20)->default('backlog');
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['project_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_item_states');
    }
};
