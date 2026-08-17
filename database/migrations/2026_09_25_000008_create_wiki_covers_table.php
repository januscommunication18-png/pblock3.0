<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A collection's Cover Page (docs/features/wiki-cover-page.md).
 *
 * ONE row per collection, not one per workspace as the source requirements had it: the cover is
 * configured from the collection's own Group view, and a workspace-wide setting reachable from
 * inside every collection is one setting wearing N local disguises.
 *
 * Everything here is presentation. Nothing in this table decides who may read anything — that
 * stays with `wiki_collections.visibility` and `wiki_collection_members`, so the cover cannot
 * become a second, quieter answer to a question already answered elsewhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wiki_covers', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            // Unique: a collection has one front door. cascadeOnDelete, because a cover for a
            // collection that no longer exists is not a record of anything.
            $table->foreignId('wiki_collection_id')->unique()
                ->constrained('wiki_collections')->cascadeOnDelete();

            /*
             * Default OFF, against the source document's recommendation of ON.
             *
             * Every collection that exists today was written without a cover; defaulting to
             * enabled would change what readers see on deploy, for collections whose authors
             * were never asked. Turning it on is one click and it is theirs to make.
             */
            $table->boolean('is_enabled')->default(false);

            $table->string('title', 100)->nullable();
            $table->string('short_description', 300)->nullable();

            $table->boolean('global_search_enabled')->default(true);
            $table->boolean('previous_next_enabled')->default(true);
            $table->boolean('on_this_page_enabled')->default(true);

            // Strings rather than enum columns: `wiki_collections.visibility` and `.status` are
            // strings for the same reason — the set grows, and a migration to add a value to an
            // enum is a table rewrite for what should be a line of validation.
            $table->string('content_alignment', 10)->default('center');
            $table->string('card_layout', 10)->default('auto');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wiki_covers');
    }
};
