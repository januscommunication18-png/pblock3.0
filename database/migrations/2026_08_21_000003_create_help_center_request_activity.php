<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Everything that has happened to a Request (docs/features/help-center.md, P36). TENANT-SCOPED.
 *
 * The table P28 and P33 both recorded as missing. Deliberately the SAME SHAPE as
 * `work_item_activity` — event, field, old_value, new_value, meta — because the Help Center is
 * asked for the same four readings of it that a work item already has:
 *
 *   Activity   — every row, phrased as a sentence
 *   Transition — the rows where `field` is `status`
 *   History    — every row, phrased as old → new
 *   All        — these rows interleaved with the messages and the updates
 *
 * One table for four tabs rather than four tables: they are four questions about one list, and a
 * transition log separate from an activity log is two records of the same event, free to
 * disagree the first time one of them is written and the other is not.
 *
 * Values are TEXT, not typed columns. An old status is a name, an old assignee is a person, an
 * old priority is a word — what they have in common is that they are being displayed, and a
 * history that renders a status id is a history nobody can read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_center_request_activity', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->foreignId('help_center_request_id')
                ->constrained('help_center_requests')->cascadeOnDelete();

            /*
             * Who did it — NULLABLE, and null means the system.
             *
             * A Request opened by an inbound email has no actor; neither does an auto-assignment
             * (P26) or a status change made by a rule. "Nobody did this, it happened" is a real
             * answer and the timeline says so rather than inventing an account to blame.
             */
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();

            // `created`, `assigned`, `status_changed`, `priority_changed`, `tagged`, `untagged`,
            // `spam_marked`, `closed`, `reopened`, `customer_updated`.
            $table->string('event', 40);

            // Which property moved, when the event is about one — `status`, `assignee`,
            // `priority`, `tags`. Null for events that are not about a field, like `created`.
            $table->string('field', 40)->nullable();

            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();

            // Ids and anything else a renderer might want that a display string cannot carry.
            $table->json('meta')->nullable();

            $table->timestamps();

            // The one read this table has: a Request's rows, oldest first.
            $table->index(['help_center_request_id', 'created_at'], 'hc_activity_request_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('help_center_request_activity');
    }
};
