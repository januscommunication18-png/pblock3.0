<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Internal updates on a Request (docs/features/help-center.md, P36). TENANT-SCOPED.
 *
 * The same shape as `work_item_updates` — author, status, content, edited_at, soft deletes —
 * because the requirement asks for the same feature: "Do not create a separate implementation
 * unless necessary. Reuse the existing update editor, display, permissions, empty states."
 *
 * Two columns of `work_item_updates` are NOT here. `progress_percent` and the subtask counts
 * describe how far through a piece of work somebody is; a Request is not worked in subtasks and
 * has no percentage to be at, so carrying them would be three columns that are always null and
 * a form with three fields nobody can fill in.
 *
 * INTERNAL, and the table says so by what it lacks: no recipient, no message id, no delivery
 * state. An update is not an email and cannot become one — replying to the customer writes a
 * `help_center_messages` row instead, which is the table that knows about addresses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_center_request_updates', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->foreignId('help_center_request_id')
                ->constrained('help_center_requests')->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();

            // on_track | at_risk | off_track — the work item vocabulary, unchanged, so an agent
            // reading both reads one word list.
            $table->string('status', 20);

            $table->longText('content');
            $table->timestamp('edited_at')->nullable();

            $table->timestamps();
            // Soft, like the work item's: an update somebody wrote and withdrew is still part of
            // what happened, and a hard delete would take it out of a timeline that claims to be
            // complete.
            $table->softDeletes();

            $table->index(['help_center_request_id', 'created_at'], 'hc_updates_request_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('help_center_request_updates');
    }
};
