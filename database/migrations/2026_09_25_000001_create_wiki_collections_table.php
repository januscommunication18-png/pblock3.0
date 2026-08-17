<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wiki collections (docs/features/wiki.md) — a home for related pages.
 *
 * TENANT-SCOPED (CLAUDE.md §7). A collection belongs to the workspace, not to a project:
 * that is the whole distinction between Wiki and Project Pages.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wiki_collections', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->string('name', 120);
            $table->text('description')->nullable();

            // public = everyone in the workspace; private = explicitly invited only.
            // A string rather than a boolean because the requirements already hint at more
            // than two answers later, and `is_private = false` reads badly at the call site.
            $table->string('visibility', 20)->default('public');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            // Manual ordering: "documentation should follow a logical sequence — not simply
            // the date it was created".
            $table->unsignedInteger('position')->default(0);

            // Archived, never deleted: "keep the history without keeping the clutter".
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'archived_at', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wiki_collections');
    }
};
