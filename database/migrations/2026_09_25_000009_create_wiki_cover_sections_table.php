<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Cover Page's section cards (docs/features/wiki-cover-page.md).
 *
 * Configured cards REPLACE the ones derived from the collection's own arrangement. A cover with
 * no rows here still shows a grid — see WikiReader::coverCards() — so adding the first card is
 * taking control of something already working, not switching an empty feature on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wiki_cover_sections', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('wiki_cover_id')->constrained('wiki_covers')->cascadeOnDelete();

            // `icon` or `none` today. `banner` arrives with the upload that gives it something
            // to show — a visual type that cannot be pictured is a choice that does nothing.
            $table->string('visual_type', 10)->default('none');
            $table->string('icon_key', 60)->nullable();

            $table->string('title', 80);
            $table->string('description', 200)->nullable();

            /*
             * Two tables, one column — so no foreign key. `destination_id` names a
             * wiki_collection_groups row or a wiki_pages row, and which one is in
             * `destination_type`.
             *
             * Validity is enforced on write and re-checked on read, because a destination can be
             * deleted from a screen that has never heard of covers. A card pointing at nothing
             * is flagged in Settings and hidden from readers rather than serving a broken link.
             */
            $table->string('destination_type', 20);
            $table->unsignedBigInteger('destination_id');

            $table->unsignedInteger('position')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['wiki_cover_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wiki_cover_sections');
    }
};
