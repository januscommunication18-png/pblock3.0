<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who may open a private collection, and what they may do there (docs/features/wiki.md).
 *
 * TENANT-SCOPED. "Access follows the collection so you don't have to configure every page
 * individually" — so membership hangs off the collection, never off a page.
 *
 * Rows exist for PUBLIC collections too: a public collection is readable by the whole
 * workspace, but somebody still has to be named to EDIT it. Visibility answers "who can see
 * this"; this table answers "who can change it".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wiki_collection_members', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('wiki_collection_id')->constrained('wiki_collections')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // read | edit. `comment` is named in the requirements as planned, so this is a
            // string rather than a boolean that would have to be widened later.
            $table->string('permission', 20)->default('read');

            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            // One membership per person per collection, enforced rather than hoped for.
            $table->unique(['wiki_collection_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wiki_collection_members');
    }
};
