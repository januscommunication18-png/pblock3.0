<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Internal notes on a Request (docs/features/help-center.md, P42). TENANT-SCOPED.
 *
 * The work item COMMENT's shape — author, content, edited_at, soft deletes — because that is
 * what an internal note is: a conversation between colleagues attached to a record. It is not
 * an update (P36), which is a status somebody is reporting, and it is not a message (P6), which
 * is an email that left the building.
 *
 * INTERNAL by construction, and the table is where that guarantee lives: there is no recipient
 * column, no message id, no delivery state. Nothing here could be emailed to a customer even by
 * a mistake in a controller, because there is nowhere to put the address.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_center_request_notes', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->foreignId('help_center_request_id')
                ->constrained('help_center_requests')->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();

            $table->longText('content');

            /*
             * Who was named, as a list of user ids.
             *
             * NOT a row in the `mentions` table, which is the right long-term home and is
             * work-item-shaped today: `mentions.work_item_id` is required, and making it
             * nullable to admit a second kind of source is a change to a table another feature
             * owns and a migration over its rows.
             *
             * A JSON list is enough for what this feature does with it — render the names on the
             * note, and email the people in it. The day the notification Inbox should show a
             * Help Center mention beside a work item one, they become `mentions` rows with a
             * `source_type` of their own; nothing above this column depends on the difference.
             */
            $table->json('mentions')->nullable();

            $table->timestamp('edited_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['help_center_request_id', 'created_at'], 'hc_notes_request_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('help_center_request_notes');
    }
};
