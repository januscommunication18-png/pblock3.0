<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Labels on wiki pages (docs/features/wiki.md).
 *
 * `wiki_labels` has existed since the Settings screen shipped, with nothing able to carry one.
 * The pivot is what makes the Label column on a collection something other than an empty
 * column that can never be filled.
 *
 * NO `tenant_id` here, unlike every other table in this feature. Both sides are already
 * tenant-scoped, so a row can only ever join a page and a label from the same workspace — and
 * a third copy of the tenant is a third place it can disagree with the other two.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wiki_page_label', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wiki_page_id')->constrained('wiki_pages')->cascadeOnDelete();
            $table->foreignId('wiki_label_id')->constrained('wiki_labels')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['wiki_page_id', 'wiki_label_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wiki_page_label');
    }
};
