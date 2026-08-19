<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Outbound delivery events (docs/features/help-desk.md — Phase 2, slice 3: FR-2.9).
 *
 * "Failed outbound delivery creates a visible delivery event and notification" — this table is
 * the visible half. A reply that bounces is otherwise the worst kind of failure a help desk
 * can have: silent. The agent believes they answered, the customer never heard, and the case
 * sits there looking handled.
 *
 * TENANT-SCOPED. Append-only by intent: an event is what a provider told us at a moment in
 * time, and rewriting it would make the history of a delivery unreadable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_desk_email_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('help_desk_id')->constrained('help_desks')->cascadeOnDelete();
            $table->foreignId('help_desk_conversation_id')->constrained('help_desk_conversations')->cascadeOnDelete();

            // The message this is about. Nullable because a provider can report on mail we no
            // longer hold a row for, and losing the event would be worse than an orphan.
            $table->foreignId('help_desk_message_id')->nullable()
                ->constrained('help_desk_messages')->nullOnDelete();

            // queued | delivered | deferred | bounced | complained | failed
            $table->string('status', 20);

            // Who it was going to — one row per recipient, because a reply to three people can
            // succeed for two of them.
            $table->string('recipient');

            // The provider's own words. Kept verbatim: "550 5.1.1 user unknown" is the whole
            // answer to "why", and paraphrasing it loses the part an administrator can act on.
            $table->text('reason')->nullable();

            /*
             * The provider's id for this event, when it gives one.
             *
             * Unique per Help Desk, which is what makes the delivery webhook idempotent the same
             * way ingestion is: providers retry, and a retried bounce must not raise a second
             * alarm about the same failure (§9).
             */
            $table->string('event_id', 190)->nullable();

            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            $table->unique(['help_desk_id', 'event_id'], 'hd_deliveries_event_unique');
            $table->index(['help_desk_conversation_id', 'id'], 'hd_deliveries_conversation_idx');
            $table->index(['help_desk_id', 'status'], 'hd_deliveries_status_idx');
        });

        Schema::table('help_desk_conversations', function (Blueprint $table) {
            /*
             * When this conversation last had a delivery fail, and nothing has succeeded since.
             *
             * A denormalized flag rather than a subquery: every list of conversations wants to
             * show it, and asking the deliveries table per row is the N+1 §13 forbids. Set on
             * the first failure and cleared by a later success, so it means "currently broken"
             * rather than "was ever broken" — the second is what the deliveries table is for.
             */
            $table->timestamp('delivery_failed_at')->nullable()->after('last_message_at');
        });
    }

    public function down(): void
    {
        Schema::table('help_desk_conversations', function (Blueprint $table) {
            $table->dropColumn('delivery_failed_at');
        });

        Schema::dropIfExists('help_desk_email_deliveries');
    }
};
