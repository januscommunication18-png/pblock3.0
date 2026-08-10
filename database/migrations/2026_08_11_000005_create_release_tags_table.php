<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * release_tags (spec §8) — version-style release tags (e.g. v1.0.0). TENANT-SCOPED
 * (BelongsToTenant). Color is optional (tags in the POC carry a name; a color may be set).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('release_tags', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->string('name');
            $table->string('color', 7)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('release_tags');
    }
};
