<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sub-groups (docs/features/wiki.md).
 *
 * A section inside a section: "Getting started → Installation". One column, self-referencing,
 * rather than a second table — a sub-group is a group in every other respect, and splitting
 * them would double every query that lists sections.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wiki_collection_groups', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->after('wiki_collection_id')
                ->constrained('wiki_collection_groups')
                // The parent going means the children are promoted, not orphaned — the
                // controller does that explicitly, so this is the safety net beneath it.
                ->nullOnDelete();

            $table->index(['parent_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::table('wiki_collection_groups', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
            $table->dropIndex(['parent_id', 'position']);
            $table->dropColumn('parent_id');
        });
    }
};
