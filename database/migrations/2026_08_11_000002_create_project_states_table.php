<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * project_states (spec §6 / SET-PROJ-001..004) — workspace-wide project lifecycle states,
 * applied across every project. TENANT-SCOPED (BelongsToTenant). Seeded with the six
 * approved defaults when a workspace's settings are first provisioned; `is_default` marks
 * the protected default state and `group` drives the visual lifecycle grouping.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_states', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->string('name');
            $table->string('color', 7);          // #RRGGBB
            $table->string('description')->nullable();
            $table->string('group', 20)->default('backlog'); // lifecycle group
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_states');
    }
};
