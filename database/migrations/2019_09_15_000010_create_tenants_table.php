<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The `tenants` table backs the Workspace model (App\Models\Workspace) — a workspace
 * IS the tenant in this single-database multi-tenant app (Phase 3, spec §8).
 *
 * `id` is a durable UUID internal key (stancl UUIDGenerator); `slug` is the mutable,
 * globally-unique human-readable locator (spec §8, WS-004/005). Columns declared here
 * are real columns; anything not listed in Workspace::getCustomColumns() would instead
 * be folded into the stancl `data` JSON column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->string('id')->primary(); // UUID internal key (spec §8)

            $table->string('name');
            $table->string('slug')->unique();                 // WS-004/005: globally unique
            $table->string('company_size', 20)->nullable();   // WS-006 team size bucket
            $table->string('timezone', 64)->nullable();
            $table->string('logo_url')->nullable();
            $table->string('view_type', 20)->default('agile'); // WS-VIEW-001/004: agile only for now
            $table->string('status', 20)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->json('data')->nullable(); // stancl overflow column (unused custom attrs)

            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
