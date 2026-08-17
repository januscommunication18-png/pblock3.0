<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Groups inside a collection (docs/features/wiki.md) — the Group view's sections.
 *
 * A second way to look at the same pages, not a second place to keep them: a page still
 * belongs to exactly one collection, and a group only says where it sits inside it. That is
 * why `wiki_group_id` hangs off the page rather than a pivot — a page is in one group or none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wiki_collection_groups', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('wiki_collection_id')->constrained('wiki_collections')->cascadeOnDelete();

            $table->string('name', 120);
            // A short tag shown beside the name — "Internal", "v2", "Deprecated".
            $table->string('label', 60)->nullable();
            // One line, for the group header. The long one is the explanation underneath.
            $table->string('short_description', 200)->nullable();
            $table->text('long_description')->nullable();

            $table->unsignedInteger('position')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['wiki_collection_id', 'position']);
        });

        Schema::table('wiki_pages', function (Blueprint $table) {
            /*
             * nullOnDelete, never cascade. Deleting a group is a decision about how the
             * collection is ARRANGED; taking its pages with it would make an organising
             * action destructive, which is not what anybody means by "remove this group".
             */
            $table->foreignId('wiki_group_id')->nullable()->after('parent_id')
                ->constrained('wiki_collection_groups')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('wiki_pages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('wiki_group_id');
        });

        Schema::dropIfExists('wiki_collection_groups');
    }
};
