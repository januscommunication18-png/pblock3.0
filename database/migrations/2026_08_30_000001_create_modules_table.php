<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * modules (Module Management §18) — a container for related work items inside one project.
 *
 * TENANT-SCOPED (CLAUDE.md §7) *and* project-scoped: `tenant_id` for the BelongsToTenant
 * global scope plus `project_id`, so a module can never be read outside its workspace or leak
 * between projects (§20 Security).
 *
 * `status` IS stored here, unlike a cycle's derived status: a module's lifecycle state is a
 * decision someone makes (§6.5 — "ProjectBlock should support manual completion"), not a
 * function of the calendar. Nothing infers it, so there is nothing to drift.
 *
 * Dates are nullable: §6.1 allows a Backlog module with no schedule at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modules', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('backlog');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->foreignId('lead_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            // §13.1 recommends soft delete, so a module's history survives its removal.
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            // Drives the list: a project's active modules, newest first.
            $table->index(['project_id', 'archived_at']);
            $table->index(['project_id', 'status']);
        });

        // §18: module members are independent of work item assignees — being on a module says
        // you are involved in that body of work, nothing more (§5.2).
        Schema::create('module_members', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('module_id')->constrained('modules')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['module_id', 'user_id']);
        });

        /*
         * §9.3: a work item may belong to SEVERAL modules — a functional module and a release
         * module at once — so this is many-to-many, unlike the single cycle_id column.
         *
         * The unique pair is §15's "duplicate module-work item relationships must not be
         * created", enforced by the database rather than by remembering to check.
         */
        Schema::create('module_work_items', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('module_id')->constrained('modules')->cascadeOnDelete();
            $table->foreignId('work_item_id')->constrained('work_items')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['module_id', 'work_item_id']);
            // "Which modules is this work item in?" — the work item's own property.
            $table->index('work_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_work_items');
        Schema::dropIfExists('module_members');
        Schema::dropIfExists('modules');
    }
};
