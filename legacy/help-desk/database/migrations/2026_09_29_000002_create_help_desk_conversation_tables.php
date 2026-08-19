<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conversations and their messages (docs/features/help-desk.md — Phase 2, slice 2:
 * FR-2.4 threading and ingestion, FR-2.7 numbering).
 *
 * Two tables, not the four the phase document lists (decisions H17 and H18):
 *
 *   `conversation_threads`      — a conversation IS the thread. The identifiers that thread mail
 *                                 together (Message-ID, In-Reply-To, References) belong to the
 *                                 messages that carry them, and a third table holding the same
 *                                 relationship would be a second answer to "which conversation
 *                                 is this reply part of?".
 *   inbound/outbound as tables  — one `help_desk_messages` table with a `direction`. Everything
 *                                 that makes a message a message — who it is from, its subject,
 *                                 its body, its place in a thread — is identical in both
 *                                 directions, and splitting them would mean every read of a
 *                                 conversation is a union of two tables in date order.
 *
 * Both are TENANT-SCOPED (CLAUDE.md §7).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_desk_conversations', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('help_desk_id')->constrained('help_desks')->cascadeOnDelete();

            // Which inbox it is in NOW. Moving between inboxes (FR-2.8) changes this column and
            // nothing else, which is why the number below does not live on the inbox.
            $table->foreignId('help_desk_inbox_id')->constrained('help_desk_inboxes');

            /*
             * The case number people say out loud (FR-2.7). Immutable once allocated — the
             * general data rules require conversation identifiers to be — and unique within the
             * Help Desk, enforced here rather than hoped for.
             */
            $table->unsignedInteger('number');

            $table->string('subject')->nullable();

            /*
             * Minimal status. Phase 3 owns the conversation lifecycle; this exists so a row
             * arriving today is not stateless, and so the index the phase asks for (inbox,
             * assignee, status, customer, created date) can be built now rather than added to a
             * large table later.
             */
            $table->string('status', 20)->default('open');

            // The customer, as the email itself described them. Phase 4 introduces customer
            // records and links these to them; until then this is what is known.
            $table->string('customer_email');
            $table->string('customer_name')->nullable();

            $table->foreignId('assignee_id')->nullable()->constrained('help_desk_members')->nullOnDelete();

            // When the conversation last had traffic — how every list of conversations is
            // ordered, so it is a stored column rather than a subquery per row.
            $table->timestamp('last_message_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            $table->unique(['help_desk_id', 'number'], 'hd_conversations_number_unique');
            /*
             * §7's "indexes must support inbox, assignee, status, customer, created date and
             * common filtering patterns". Named explicitly: the generated names run past
             * MySQL's 64-character identifier limit once three columns are involved.
             */
            $table->index(['help_desk_inbox_id', 'status', 'last_message_at'], 'hd_conversations_inbox_status_idx');
            $table->index(['tenant_id', 'assignee_id'], 'hd_conversations_assignee_idx');
            $table->index(['tenant_id', 'customer_email'], 'hd_conversations_customer_idx');
            $table->index(['help_desk_id', 'created_at'], 'hd_conversations_created_idx');
        });

        Schema::create('help_desk_messages', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');

            /*
             * Denormalized from the conversation on purpose: duplicate detection and thread
             * lookup both ask "does this Help Desk already know this Message-ID?" before any
             * conversation is known, and asking that through a join is a join on the path every
             * arriving email takes.
             */
            $table->foreignId('help_desk_id')->constrained('help_desks')->cascadeOnDelete();
            $table->foreignId('help_desk_conversation_id')->constrained('help_desk_conversations')->cascadeOnDelete();

            // inbound | outbound — the customer wrote it, or the Help Desk did.
            $table->string('direction', 10);

            /*
             * RFC 5322 threading headers (FR-2.4), stored exactly as they arrived.
             *
             * `message_id` is unique per Help Desk, which is what makes ingestion idempotent:
             * a provider retrying a webhook, or the same message arriving twice, hits this
             * constraint instead of creating a second copy (§9, "duplicate inbound email/retry").
             */
            /*
             * 190 characters, which is the classic index-safe length for a utf8mb4 column and
             * far longer than any Message-ID in practice (they are a local part and a domain).
             * Indexed columns cannot be arbitrarily long — MySQL caps an index at 3072 bytes,
             * and a 512-character utf8mb4 column eats two thirds of that on its own.
             */
            $table->string('message_id', 190)->nullable();
            $table->string('in_reply_to', 190)->nullable();

            // The full References chain, unindexed: it is walked in PHP against known ids, not
            // matched in SQL, and it can legitimately be thousands of characters long.
            $table->text('references')->nullable();

            $table->string('from_email');
            $table->string('from_name')->nullable();
            $table->json('to')->nullable();
            $table->json('cc')->nullable();
            $table->string('subject')->nullable();

            // Sanitized on the way IN, once (§13), rather than escaped on every read.
            $table->longText('body_html')->nullable();
            $table->longText('body_text')->nullable();

            $table->timestamp('sent_at')->nullable();

            // Null for inbound: nobody here wrote it.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            $table->unique(['help_desk_id', 'message_id'], 'hd_messages_message_id_unique');
            // Threading walks References backwards looking for anything already known.
            $table->index(['help_desk_id', 'in_reply_to'], 'hd_messages_in_reply_to_idx');
            $table->index(['help_desk_conversation_id', 'id'], 'hd_messages_conversation_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('help_desk_messages');
        Schema::dropIfExists('help_desk_conversations');
    }
};
