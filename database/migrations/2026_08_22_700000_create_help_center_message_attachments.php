<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Files that arrived with a customer's email (docs/features/help-center.md, P66). TENANT-SCOPED.
 *
 * The gap named as open since P62: Postmark's inbound webhook carries `Attachments`, and nothing
 * read them — so a ticket that said "invoice attached" arrived with no invoice, and the only
 * copy was in a webhook body nobody kept.
 *
 * Deliberately the SAME SHAPE as `work_item_attachments`, because it answers the same two
 * questions — where is the file, and who may read it. `disk` is recorded rather than assumed so
 * a row written before a `MEDIA_DISK` switch keeps resolving to the disk it actually landed on.
 *
 * ## Why the Request id is here as well as the message's
 *
 * Authorization resolves Request → Space → policy, and every read of this table is either "this
 * message's files" or "may this person see this file". Carrying the Request saves a join on the
 * second and matches how `work_item_attachments` carries `project_id` beside `work_item_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_center_message_attachments', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();

            $table->foreignId('help_center_request_id')
                ->constrained('help_center_requests')->cascadeOnDelete();
            $table->foreignId('help_center_message_id')
                ->constrained('help_center_messages')->cascadeOnDelete();

            $table->string('disk', 32)->default('local');
            $table->string('path');

            // The name the SENDER gave it — what the list shows and the download is named.
            // Sanitised before it is stored; see InboundAttachmentStore::safeName().
            $table->string('name');

            /*
             * The content type the sender CLAIMED.
             *
             * Recorded, not trusted. It is echoed on the download beside a
             * `Content-Disposition: attachment`, never used to decide whether to render
             * something inline — a stranger's `text/html` is not a reason to run their markup
             * in our origin.
             */
            $table->string('mime', 191)->default('application/octet-stream');
            $table->unsignedBigInteger('size')->default(0);

            /*
             * The `Content-ID` of an image the sender embedded in the body, and the flag for it.
             *
             * Kept rather than dropped because the two cases are indistinguishable: a signature
             * logo and a pasted screenshot both arrive this way, and the screenshot is usually
             * the whole point of the ticket. The flag is what a later refinement would filter
             * on without having to re-ingest anything.
             */
            $table->string('content_id', 255)->nullable();
            $table->boolean('is_inline')->default(false);

            $table->timestamps();

            // The one read this table has: a message's files, oldest first.
            $table->index(['help_center_message_id', 'id'], 'hc_attach_message');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('help_center_message_attachments');
    }
};
