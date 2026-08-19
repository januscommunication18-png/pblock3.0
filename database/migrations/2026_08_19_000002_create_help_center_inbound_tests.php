<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One end-to-end inbound test (docs/features/help-center.md, P7).
 *
 * A row per attempt, because the point of the feature is to say WHERE the chain broke:
 *
 *   ProjectBlock → customer inbox → forwarding rule → Postmark Inbound → webhook → parser
 *
 * Each leg stamps its own column, so a stalled test is diagnosable from the row alone — `sent_at`
 * with no `received_at` is a forwarding problem, `received_at` with no `parsed_at` is a parsing
 * one. A single boolean would lose exactly the information the user needs.
 *
 * TENANT-SCOPED, and carries the Space and Inbox for the same reason conversations do.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_center_inbound_tests', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();

            $table->foreignId('help_center_space_id')->constrained('help_center_spaces')->cascadeOnDelete();
            $table->foreignId('help_center_inbox_id')->constrained('help_center_inboxes')->cascadeOnDelete();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();

            /*
             * The needle.
             *
             * Unique globally, not per tenant: an inbound message is matched by this token
             * before any workspace is known, exactly like the Inbox's own `inbound_id`.
             */
            $table->string('test_token', 32)->unique();

            // Both ends of the round trip, recorded as they were at the time — a later edit to
            // the Inbox must not rewrite the history of a completed test.
            $table->string('test_email_address');
            $table->string('inbound_email_address');

            $table->string('status', 20)->default('pending');

            $table->string('outbound_message_id')->nullable();
            $table->string('postmark_inbound_message_id')->nullable();

            // One timestamp per leg of the chain.
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('parsed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('failure_reason')->nullable();

            // What the parser saw, shown on the success card (§"Successful Test").
            $table->json('received_meta')->nullable();

            $table->timestamps();

            $table->index(['help_center_inbox_id', 'created_at'], 'hc_test_inbox_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('help_center_inbound_tests');
    }
};
