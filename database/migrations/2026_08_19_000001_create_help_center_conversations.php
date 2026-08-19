<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Somewhere for inbound mail to land (docs/features/help-center.md, P6).
 *
 * Postmark Inbound cannot be built without this: a webhook that parses a message and then has
 * nowhere to put it is a webhook that does nothing. So the ingestion phase brings the smallest
 * conversation model that is actually correct — a thread, and the messages in it.
 *
 * Deliberately minimal. Assignment, followers, notes, ratings, snoozing and attachments are all
 * real requirements from the source documents and none of them is here; what is here is what
 * receiving an email requires. Every column below exists because the ingestion path writes or
 * reads it.
 *
 * Both tables are TENANT-SCOPED and additionally carry `help_center_space_id`, because P3 §16
 * requires a conversation to be answerable for which Space it belongs to without a join.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_center_conversations', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();

            $table->foreignId('help_center_space_id')->constrained('help_center_spaces')->cascadeOnDelete();
            $table->foreignId('help_center_inbox_id')->constrained('help_center_inboxes')->cascadeOnDelete();

            /*
             * Where the thread sits in its Space's workflow.
             *
             * Nullable and null-on-delete rather than required: a status can be deleted from a
             * workflow, and a conversation losing its status is recoverable — a conversation
             * being deleted along with it is not.
             */
            $table->foreignId('help_center_status_id')->nullable()
                ->constrained('help_center_statuses')->nullOnDelete();

            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('subject')->nullable();

            // The customer, preserved exactly as they wrote (P1 §20 rule 9).
            $table->string('customer_email');
            $table->string('customer_name')->nullable();

            /*
             * The mail thread this conversation is, in the sender's client.
             *
             * A reply carries `In-Reply-To`/`References` pointing at a Message-ID we have already
             * seen. Storing the FIRST one lets a reply join its thread instead of opening a
             * second conversation about the same problem. Indexed because every inbound message
             * looks itself up by it.
             */
            $table->string('thread_key')->nullable()->index();

            $table->timestamp('last_message_at')->nullable();
            $table->boolean('is_spam')->default(false);
            $table->timestamp('closed_at')->nullable();

            $table->timestamps();

            // Named explicitly: the derived name (table + both columns + `_index`) is 67
            // characters, and MySQL caps identifiers at 64.
            $table->index(['help_center_space_id', 'last_message_at'], 'hc_conv_space_last_msg_idx');
        });

        Schema::create('help_center_messages', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();

            $table->foreignId('help_center_conversation_id')
                ->constrained('help_center_conversations')->cascadeOnDelete();

            // `inbound` from a customer, `outbound` from an agent. Only inbound is written today.
            $table->string('direction', 10)->default('inbound');

            $table->string('from_email');
            $table->string('from_name')->nullable();

            /*
             * The original To and Cc, kept whole (P1 §20 rule 10).
             *
             * Required "for conversation history and reply handling": replying to everybody who
             * was on the original message is impossible if only our own inbound address was
             * recorded. JSON, because it is a list read back only with its message.
             */
            $table->json('to_recipients')->nullable();
            $table->json('cc_recipients')->nullable();

            $table->string('subject')->nullable();
            $table->longText('body_text')->nullable();
            $table->longText('body_html')->nullable();

            /*
             * The provider's own identifiers.
             *
             * `message_id` is the RFC 5322 Message-ID and is what makes ingestion IDEMPOTENT:
             * Postmark retries a webhook that did not return 200, and without a unique key on
             * this a retry would post the customer's email into the thread a second time.
             */
            $table->string('message_id')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->timestamp('received_at')->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'message_id'], 'hc_msg_tenant_message_id_unq');
            $table->index(['help_center_conversation_id', 'received_at'], 'hc_msg_conv_received_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('help_center_messages');
        Schema::dropIfExists('help_center_conversations');
    }
};
