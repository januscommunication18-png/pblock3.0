<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inbox email identity (docs/features/help-desk.md — Phase 2, slice 1: FR-2.1/2.2/2.3/2.5/2.7).
 *
 * Phase 1 gave an inbox a name, which was all inbox-level access needed. Phase 2 is what makes
 * an inbox a place mail arrives at and leaves from.
 *
 * There is no separate `email_addresses` table (decision H16): an inbox has exactly one inbound
 * address and one outbound identity in this phase, and a table would model a many-to-one that
 * does not exist yet — while making "which inbox does this address belong to?" a join on the
 * hottest path in the system.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('help_desk_inboxes', function (Blueprint $table) {
            /*
             * The address customers write to (FR-2.2).
             *
             * UNIQUE ACROSS EVERY TENANT, deliberately — this is the one column in the Help Desk
             * that is not scoped to a workspace, because the outside world is not. An arriving
             * message carries an address and nothing else; if two workspaces could both claim
             * support@acme.example, routing it would be a guess, and a guess here means one
             * workspace's customer email landing in another's inbox.
             */
            $table->string('inbound_address')->nullable()->unique()->after('name');

            // Who replies appear to come from (FR-2.3). Null falls back to the application's
            // configured sender, so an inbox is never in a state where it cannot answer.
            $table->string('outbound_from_name')->nullable()->after('inbound_address');
            $table->string('outbound_from_address')->nullable()->after('outbound_from_name');

            /*
             * Where new conversations land by default (FR-2.5).
             *
             * A MEMBER, not a user: assignment is a Help Desk fact, and pointing at a user would
             * let somebody who is not in the Help Desk be the default assignee. `nullOnDelete`
             * so removing a member does not take the inbox's configuration with them — the
             * inbox simply stops auto-assigning.
             *
             * Default TEAM (also named in FR-2.5) is deferred: teams arrive in Phase 7, and a
             * column pointing at a table that does not exist is not a foundation.
             */
            $table->foreignId('default_assignee_id')->nullable()->after('outbound_from_address')
                ->constrained('help_desk_members')->nullOnDelete();
        });

        Schema::table('help_desks', function (Blueprint $table) {
            /*
             * The conversation numbering counter (FR-2.7).
             *
             * Per HELP DESK rather than per inbox: a number is how people refer to a case out
             * loud, and moving a conversation between inboxes (FR-2.8) must not renumber it or
             * make two cases share a number. Allocated under a row lock, so two messages
             * arriving in the same instant cannot take the same number.
             */
            $table->unsignedInteger('conversation_sequence')->default(0)->after('setup_completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('help_desks', function (Blueprint $table) {
            $table->dropColumn('conversation_sequence');
        });

        Schema::table('help_desk_inboxes', function (Blueprint $table) {
            $table->dropForeign(['default_assignee_id']);
            $table->dropColumn(['inbound_address', 'outbound_from_name', 'outbound_from_address', 'default_assignee_id']);
        });
    }
};
