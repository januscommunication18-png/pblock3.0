<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * inbox_notifications — one person's attention items (docs/features/inbox.md, §32).
 *
 * TENANT-SCOPED (CLAUDE.md §7). Deliberately NOT an activity log: §41 is explicit that reading
 * an Inbox entry is private user metadata and must never reach the work item's history. These
 * rows answer "what still needs me", and are removed from the active list once reviewed.
 *
 * `type` is open-ended on purpose (§33): Phase 1 writes `assignment` and `mention`, and the
 * list names seven more to come. Keeping it a string rather than an enum column is what lets
 * the next one be added without a migration on a table this size.
 *
 * `source_type`/`source_id` say what the notification is ABOUT — a work item's description or
 * one comment — so a mention on a comment can navigate to that comment (§13) while an
 * assignment simply opens the item. `comment_id` is separate and nullable because §19 has to
 * find and remove notifications when a comment is deleted, which a polymorphic pair cannot be
 * indexed for as cheaply.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbox_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            // Whose inbox this is. Every query starts here.
            $table->foreignId('recipient_id')->constrained('users')->cascadeOnDelete();
            // Who caused it. Null when the system did.
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 32);

            $table->foreignId('project_id')->nullable()->constrained('projects')->cascadeOnDelete();
            // §30: deleting a work item takes its notifications with it, so the Inbox can
            // never offer a row that opens onto nothing.
            $table->foreignId('work_item_id')->nullable()->constrained('work_items')->cascadeOnDelete();
            // §19: the comment a mention was made in. Comments are SOFT deleted, so this
            // cannot rely on a cascade — InboxNotifier::commentDeleted does it explicitly.
            $table->foreignId('comment_id')->nullable()->constrained('work_item_comments')->cascadeOnDelete();

            $table->string('source_type', 32)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();

            // A short line of context, frozen at write time (§12/§42): the list must not have
            // to load a full work item per row, and an excerpt of what was said should not
            // change under the reader when the comment is later edited.
            $table->string('title')->nullable();
            $table->text('excerpt')->nullable();

            // Null means unread. A timestamp rather than a boolean, because "when did I deal
            // with this" is the question a digest or a snooze feature would need next.
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            // §42: the Inbox's own query — one person's unread, newest first.
            $table->index(['recipient_id', 'read_at', 'created_at'], 'inbox_recipient_read_index');
            // …and the same narrowed to a tab.
            $table->index(['recipient_id', 'type', 'read_at'], 'inbox_recipient_type_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_notifications');
    }
};
