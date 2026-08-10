<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * customer_properties (spec §11 / SET-CUST-003..006) — user-defined custom properties on
 * customer records. TENANT-SCOPED (BelongsToTenant). `type` is one of the configured
 * customer property types; `options` holds Dropdown choices. System/default properties are
 * config-defined (display-only) and are NOT stored here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_properties', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->string('title');
            $table->string('description')->nullable();
            $table->string('type', 20);              // Text | Number | Date | Dropdown | ...
            $table->boolean('mandatory')->default(false);
            $table->boolean('active')->default(true);
            $table->json('options')->nullable();      // Dropdown choices
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_properties');
    }
};
