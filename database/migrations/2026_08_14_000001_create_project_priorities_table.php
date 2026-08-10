<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * project_priorities — workspace-wide project priority labels (Urgent/High/Medium/Low/None),
 * editable and renamable in Settings → Projects. TENANT-SCOPED (BelongsToTenant). Seeded with
 * the five defaults when a workspace's settings are first provisioned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_priorities', function (Blueprint $table) {
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
        Schema::dropIfExists('project_priorities');
    }
};
