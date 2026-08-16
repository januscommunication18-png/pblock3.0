<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * mentions — who was named in a piece of rich text (docs/features/mentions.md).
 *
 * TENANT-SCOPED (CLAUDE.md §7). The row is the record; the HTML is only its presentation.
 * §23 is explicit that the editor is not the source of truth, so a mention exists because the
 * backend validated it, not because a `<span data-user-id>` arrived in a request.
 *
 * POLYMORPHIC source, because the same mention means the same thing in a work item description
 * and in a comment, and §2 lists five more surfaces to come (pages, wiki, updates…). One table
 * with `source_type`/`source_id` is what lets the Inbox later read "everywhere I was named"
 * with one query rather than one per surface.
 *
 * `UNIQUE(source_type, source_id, user_id)` is §14's deduplication, at the database rather
 * than in the code that writes it: naming somebody twice in one comment is one mention.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mentions', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            // Where the mention lives: 'work_item' | 'comment' (see Mention::SOURCES).
            $table->string('source_type', 32);
            $table->unsignedBigInteger('source_id');
            // The work item it ultimately belongs to, denormalised — a comment mention has to
            // navigate back to the item (§20), and the Inbox should not join through every
            // possible source table to find out which one.
            $table->foreignId('work_item_id')->nullable()->constrained('work_items')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // Who did the naming. Kept even though the source has an author: an edit can add a
            // mention, and it is the editor who mentioned you, not whoever wrote it first.
            $table->foreignId('mentioned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['source_type', 'source_id', 'user_id'], 'mentions_source_user_unique');
            // "Everything I have been mentioned in, newest first" — the Inbox's only query.
            $table->index(['tenant_id', 'user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mentions');
    }
};
