<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * cycles (Cycles §16) — a time-boxed sprint inside one project.
 *
 * TENANT-SCOPED (CLAUDE.md §7) *and* project-scoped: `tenant_id` for the BelongsToTenant
 * global scope plus `project_id`, so a cycle can never be read outside its workspace or leak
 * between projects (§18 "Project isolation").
 *
 * There is deliberately NO `status` column, though §16 suggests one. Status is a pure
 * function of the dates and today (§9), so storing it would need a scheduled job to stay
 * truthful and would disagree with §9's own definition between runs. `completed_at` is a
 * different fact — the moment a user explicitly closed the cycle out — and IS stored, because
 * §10's transfer flow acts on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cycles', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            // Drives both the landing page (a project's cycles in date order) and the overlap
            // check, which scans one project's ranges.
            $table->index(['project_id', 'start_date', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cycles');
    }
};
