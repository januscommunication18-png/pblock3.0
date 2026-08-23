<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conversations become REQUESTS (docs/features/help-center.md, P9).
 *
 * The product has one primary object and it is now named once, everywhere: an inbound email
 * creates a **Request**, which carries a human-readable **Ticket Number** and is worked through
 * a Space's **Inbox**. "Conversation", "case", "ticket" and "email" were four words for that one
 * object, and four words for one thing is four chances for two screens to mean different things.
 *
 * A rename rather than a new table: the rows are the same rows, and copying them would leave two
 * places where a Request could live.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('help_center_conversations', 'help_center_requests');

        Schema::table('help_center_messages', function (Blueprint $table) {
            $table->renameColumn('help_center_conversation_id', 'help_center_request_id');
        });

        Schema::table('help_center_requests', function (Blueprint $table) {
            /*
             * The Ticket Number, stored as the integer it counts and formatted for display.
             *
             * Deliberately NOT the primary key (P9): the id is an internal handle that may be a
             * UUID tomorrow, while the ticket number is a promise made to a customer in an email
             * subject line. Storing "#000011" as a string would make "the next one" a parsing
             * problem, so the sequence is a number and the padding is presentation.
             *
             * Nullable only so existing rows can be backfilled below; every new Request gets one.
             */
            $table->unsignedBigInteger('ticket_number')->nullable()->after('help_center_inbox_id');

            /** The first ~200 characters of the inbound message, for the Inbox list. */
            $table->text('preview')->nullable()->after('subject');

            $table->string('priority', 20)->default('normal')->after('preview');

            /**
             * When the CURRENT waiting period started (P9, Waiting Period).
             *
             * Reset every time responsibility changes hands, which is what makes this a waiting
             * time rather than the age of the ticket — the distinction the requirement is
             * explicit about. Null means nobody is waiting (a status whose waiting_on is
             * "neither"), which is a different fact from "waiting for zero minutes".
             */
            $table->timestamp('waiting_since')->nullable()->after('last_message_at');

            /** The latest relevant activity — a reply, a status change, an assignment. */
            $table->timestamp('last_activity_at')->nullable()->after('waiting_since');

            /*
             * Unique PER TENANT, not globally.
             *
             * Two workspaces both having a Request #000011 is correct — the number is theirs, and
             * a global sequence would leak how much traffic every other workspace handles.
             */
            $table->unique(['tenant_id', 'ticket_number'], 'hc_requests_tenant_ticket_unique');
        });

        Schema::table('help_center_statuses', function (Blueprint $table) {
            /*
             * The workflow's starting status (P9, Default Status).
             *
             * A flag rather than "the one at position 0": position is a drag-and-drop artefact,
             * and a team that reorders its workflow has not thereby changed where new email
             * lands. Exactly one per Space, enforced in HelpCenterStatus.
             */
            $table->boolean('is_default')->default(false)->after('is_active');

            /**
             * WHO the Request is waiting on while it sits in this status (P9).
             *
             * Distinct from `responsibility`, which decides who gets ASSIGNED. This decides whose
             * clock is running: 'agent', 'customer' or 'neither'. Without it the Inbox can only
             * report how old a ticket is, which tells an agent nothing about what needs doing.
             */
            $table->string('waiting_on', 20)->default('agent')->after('responsibility');
        });

        $this->backfill();
    }

    /**
     * Give the rows that predate this migration what the new columns mean.
     *
     * Raw statements rather than models: a model here would be the model as it is TODAY, and this
     * migration must keep working when that model changes again.
     */
    private function backfill(): void
    {
        // Every Space's Open status starts its workflow, and Closed stops the clock — which is
        // what those two already meant before there was a column saying so.
        DB::table('help_center_statuses')->where('system_key', 'open')
            ->update(['is_default' => true, 'waiting_on' => 'agent']);

        DB::table('help_center_statuses')->where('system_key', 'closed')
            ->update(['waiting_on' => 'neither']);

        // Existing Requests: number them per tenant in creation order, so the sequence reads the
        // way it would have if it had always been there.
        foreach (DB::table('help_center_requests')->distinct()->pluck('tenant_id') as $tenantId) {
            $n = 0;

            $rows = DB::table('help_center_requests')
                ->where('tenant_id', $tenantId)->orderBy('id')->pluck('id');

            foreach ($rows as $id) {
                DB::table('help_center_requests')->where('id', $id)->update([
                    'ticket_number' => ++$n,
                ]);
            }
        }

        DB::statement('UPDATE help_center_requests SET last_activity_at = last_message_at, waiting_since = last_message_at WHERE last_activity_at IS NULL');
    }

    public function down(): void
    {
        Schema::table('help_center_statuses', function (Blueprint $table) {
            $table->dropColumn(['is_default', 'waiting_on']);
        });

        Schema::table('help_center_requests', function (Blueprint $table) {
            $table->dropUnique('hc_requests_tenant_ticket_unique');
            $table->dropColumn(['ticket_number', 'preview', 'priority', 'waiting_since', 'last_activity_at']);
        });

        Schema::table('help_center_messages', function (Blueprint $table) {
            $table->renameColumn('help_center_request_id', 'help_center_conversation_id');
        });

        Schema::rename('help_center_requests', 'help_center_conversations');
    }
};
