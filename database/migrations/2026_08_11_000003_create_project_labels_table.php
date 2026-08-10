<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * project_labels (spec §6 / SET-PROJ-005/006) — workspace-scoped, reusable across every
 * project. TENANT-SCOPED (BelongsToTenant).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_labels', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->string('name');
            $table->string('color', 7);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_labels');
    }
};
