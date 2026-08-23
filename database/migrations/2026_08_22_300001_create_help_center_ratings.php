<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One rating request, and the rating it may become (docs/features/help-center.md, P56).
 * TENANT-SCOPED.
 *
 * The REQUEST and the RESPONSE are one row, not two. A request that was never answered is this
 * row with a null `score`, which is exactly what the response-rate metric needs to count — two
 * tables would mean a LEFT JOIN to answer "how many did we ask?", and a rating that exists
 * without a request it answers.
 *
 * Every field the requirement's §21 asks to store is here: ticket, customer, Space, agent, value,
 * type, comment, submitted date, request date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_center_ratings', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->foreignId('help_center_space_id')->constrained('help_center_spaces')->cascadeOnDelete();
            $table->foreignId('help_center_request_id')->constrained('help_center_requests')->cascadeOnDelete();
            $table->foreignId('help_center_customer_id')->nullable()
                ->constrained('help_center_customers')->nullOnDelete();

            /*
             * The agent this rating is ABOUT — captured when the request is created, not read
             * later off the ticket.
             *
             * The requirement asks for "the final assigned agent at the time of resolution", and
             * a ticket reassigned next week must not silently move last week's score onto
             * somebody who never touched it. That is the whole reason this is a column rather
             * than a join.
             */
            $table->foreignId('agent_id')->nullable()->constrained('users')->nullOnDelete();

            /*
             * The secret in the customer's link.
             *
             * Long and random: this is the ONLY thing standing between a stranger and somebody
             * else's ticket feedback, and it travels in an email to an address we do not control.
             * Unique so a lookup is a single indexed read with no ambiguity.
             */
            $table->string('token', 64)->unique();

            // The type AS ASKED. Stored per rating, because a Space that switches from stars to
            // emoji next month must not retroactively re-render what people already answered.
            $table->string('rating_type', 20);

            /*
             * The NORMALISED score, 1–5, null until answered.
             *
             * The requirement is explicit that every visual type stores a normalised score — a
             * thumbs-down and a one-star are the same fact, and reporting that had to know four
             * scales would be reporting nobody could total.
             */
            $table->unsignedTinyInteger('score')->nullable();
            // What the customer actually picked, before normalisation — 7 on a 1–10 scale is not
            // 4, and throwing that away would make the raw answer unrecoverable.
            $table->unsignedTinyInteger('raw_score')->nullable();
            $table->text('comment')->nullable();

            $table->timestamp('requested_at');
            // Null until the mail actually goes — a delayed request is a row that exists before
            // anything has been sent, which is what makes cancelling it on reopen possible.
            $table->timestamp('send_after')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            // Cancelled by a reopen (§6). Kept rather than deleted: "we asked and then withdrew"
            // is a different fact from "we never asked", and the first one explains a gap.
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedTinyInteger('reminders_sent')->default(0);

            $table->timestamps();

            // The two reads: a Space's ratings for reporting, and a ticket's for its detail view.
            $table->index(['help_center_space_id', 'submitted_at'], 'hc_ratings_space_submitted');
            $table->index(['help_center_request_id', 'created_at'], 'hc_ratings_request_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('help_center_ratings');
    }
};
