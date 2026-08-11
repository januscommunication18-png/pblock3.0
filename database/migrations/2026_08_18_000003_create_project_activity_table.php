<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * project_activity (Project Member Management §29) — project-level audit events, starting
 * with membership changes: added, removed, role changed.
 *
 * §29 dictates the columns: actor, action, target member, project, previous role, new role,
 * timestamp. Separate from `work_item_activity` because the subject is the project, not one
 * work item, and the two feeds are read in different places.
 *
 * TENANT-SCOPED. `target_user_id` is nullable and `target_name` is denormalized so the log
 * still reads correctly after a user is deleted from the workspace.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_activity', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 40);
            $table->foreignId('target_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('target_name')->nullable();
            $table->string('old_role', 20)->nullable();
            $table->string('new_role', 20)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['project_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_activity');
    }
};
