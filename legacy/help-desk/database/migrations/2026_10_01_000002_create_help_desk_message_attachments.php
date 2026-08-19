<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Files that arrived with an email (Inbound Email requirements §11.6, §12).
 *
 * Modelled on `work_item_attachments`, which is the pattern this codebase already uses for a
 * stored file: the disk is recorded rather than assumed, the original filename is kept for the
 * download, and the path is never guessable.
 *
 * The difference that matters: NOBODY HERE CHOSE THIS FILE. A work-item attachment was uploaded
 * by somebody with an account; this one was sent by a stranger who knows an email address. That
 * is why the path is randomized, the size is capped in config, and the download is authorized
 * through the conversation's inbox rather than by holding the URL (§13's "protect attachment
 * access with authorization and non-guessable storage references").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_desk_message_attachments', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('help_desk_id')->constrained('help_desks')->cascadeOnDelete();
            $table->foreignId('help_desk_message_id')->constrained('help_desk_messages')->cascadeOnDelete();

            // Recorded rather than assumed, so a row written before a MEDIA_DISK switch keeps
            // resolving to the disk it actually landed on.
            $table->string('disk', 32)->default('local');

            // Null when the file was too large to keep (see config `help-desk.attachments`).
            // The ROW still exists: an agent needs to know the customer sent something, even
            // when we did not store it, and silence there reads as "they sent nothing".
            $table->string('path')->nullable();

            $table->string('name');
            $table->string('mime', 128)->nullable();
            $table->unsignedBigInteger('size')->default(0);

            // Inline images reference themselves from the body HTML by Content-ID. Kept so a
            // later phase can rewrite those references at render time instead of showing a
            // broken image where the customer put a screenshot.
            $table->string('content_id', 190)->nullable();

            // Why it was not stored, when it was not — shown in place of a download link.
            $table->string('skipped_reason', 120)->nullable();

            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            // The only read pattern: this message's attachments, in the order they arrived.
            $table->index(['help_desk_message_id', 'id'], 'hd_attachments_message_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('help_desk_message_attachments');
    }
};
