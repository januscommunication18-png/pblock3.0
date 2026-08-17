<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Linked pages (docs/features/wiki-linked-pages.md).
 *
 * A Project Page linked into a collection is a `wiki_pages` ROW THAT POINTS AT IT, rather than a
 * row in a separate link table. The requirements suggested the second; the first is what lets a
 * linked page carry a `position`, a `wiki_group_id` and a `parent_id` — so it can be dragged,
 * filed under a section and nested exactly like any other page, which is most of what "appears
 * inside the collection" means. It also means no existing list has to learn to merge two sources.
 *
 * The content is still never copied: `title` and `content` stay NULL on a linked row and resolve
 * through the pointer. Filling them would be the duplication this feature exists to avoid, and
 * they would disagree with the source the first time somebody renamed it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wiki_pages', function (Blueprint $table) {
            // NULL means an ordinary Wiki page. A string rather than a boolean because there is
            // one kind of source today and the column exists to carry the second.
            $table->string('source_type', 20)->nullable()->after('content');
            // No foreign key: which table this points at is decided by `source_type`.
            $table->unsignedBigInteger('source_page_id')->nullable()->after('source_type');

            // "Do not create another identical link" (§12), enforced by the database rather than
            // by a check two simultaneous requests could both pass.
            $table->unique(['wiki_collection_id', 'source_type', 'source_page_id'], 'wiki_pages_source_unique');
        });

        // The stored title is what an ordinary page is called; a linked one has none of its own.
        Schema::table('wiki_pages', function (Blueprint $table) {
            $table->string('title', 200)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('wiki_pages', function (Blueprint $table) {
            $table->dropUnique('wiki_pages_source_unique');
            $table->dropColumn(['source_type', 'source_page_id']);
        });
    }
};
