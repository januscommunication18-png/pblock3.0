<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * project_members (Phase 4, spec §4.3 / PRJ-030/031/041) — explicit membership of a
 * project, which is what makes a PRIVATE project accessible (PRJ-031). TENANT-SCOPED
 * (BelongsToTenant), so rows are confined to the active workspace.
 *
 * On create, the ProjectCreator seeds the creator as `admin` and the chosen lead as
 * `member`. Full member-management UI is a later phase (PRJ-041); this table exists now
 * because private-project access enforcement (PRJ-032) depends on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_members', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20)->default('member'); // admin | member
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['project_id', 'user_id']);        // one membership per user per project
            $table->index('tenant_id');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_members');
    }
};
