<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wiki pages (docs/features/wiki.md) — the documents inside a collection.
 *
 * Shaped after `project_pages` on purpose: same columns, same rules, so the two behave the
 * same way and neither has to be learned twice. What differs is where they hang — a project
 * page documents a project, a wiki page belongs to a collection and outlives any project.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wiki_pages', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('wiki_collection_id')->constrained('wiki_collections')->cascadeOnDelete();
            $table->string('title', 200);
            // Rich text, sanitized on the way in by RichTextSanitizer — the same editor and
            // the same treatment project pages get.
            $table->longText('content')->nullable();

            // Pages inside pages (§"Build pages inside pages"). nullOnDelete, not cascade:
            // removing a parent must not take its children's content with it.
            $table->foreignId('parent_id')->nullable()->constrained('wiki_pages')->nullOnDelete();

            // Manual ordering (§"Control the order of your knowledge").
            $table->unsignedInteger('position')->default(0);

            $table->timestamp('archived_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            // Drives the collection's page list: live pages in the order somebody arranged.
            $table->index(['wiki_collection_id', 'archived_at', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wiki_pages');
    }
};
