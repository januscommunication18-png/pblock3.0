<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * work_item_attachments — files attached to a work item (docs/features/work-item-attachments.md).
 *
 * The sibling of `work_item_media`, and deliberately the same shape, but a different thing: media
 * is an image the editor inlined into the description HTML, an attachment is a file that belongs
 * to the work item as a record. The columns overlap because both answer the same two questions —
 * where is the file, and who is allowed to read it.
 *
 * TENANT-SCOPED (CLAUDE.md §7) and project-scoped on top: serving a file has to be authorized,
 * which means resolving it back to the project that gates it.
 *
 * `work_item_id` is NOT NULL, which is the one real difference from media. Media is uploaded
 * from an editor that may not have saved its item yet; the paperclip only exists on a work item
 * that already has an id, so an attachment without one is a row that should not have been made.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_item_attachments', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('work_item_id')->constrained('work_items')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            // Recorded rather than assumed, so a row written before a MEDIA_DISK switch keeps
            // resolving to the disk it actually landed on.
            $table->string('disk', 32)->default('local');
            $table->string('path');
            // The original client filename — what the list shows and the download is named.
            $table->string('name');
            $table->string('mime', 128);
            $table->unsignedBigInteger('size');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            // The only read pattern: this item's attachments, oldest first.
            $table->index(['work_item_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_item_attachments');
    }
};
