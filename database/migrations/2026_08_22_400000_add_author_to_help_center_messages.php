<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who WROTE an outbound message, as distinct from who it was sent AS (P62).
 *
 * Until now they were the same thing: an agent reply stored the agent's own address in
 * `from_email`, because that is what the mail actually went out as. P62 changes the outgoing From
 * to the Space's support address — which is right for the customer and wrong for two things that
 * had been quietly relying on the old meaning:
 *
 *   - the per-agent reply counts in reporting (P50) match `from_email` against a user's address,
 *   - the ticket timeline prints `from_name` to say who replied.
 *
 * Both would have kept working and started lying: every reply attributed to "Customer Support".
 * So the two facts get two columns. `from_*` is now honestly the email's From; `author_*` is the
 * person, which is what every internal reader actually wanted all along.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('help_center_messages', function (Blueprint $table) {
            $table->string('author_email')->nullable()->after('from_name');
            $table->string('author_name')->nullable()->after('author_email');
        });

        /*
         * BACKFILL: for existing outbound rows the two were the same thing, so copy them across.
         *
         * Without this, every reply sent before today would drop out of the per-agent counts the
         * moment reporting started reading the new column — a metric that silently loses its
         * history is worse than one that never had any.
         */
        DB::table('help_center_messages')
            ->where('direction', 'outbound')
            ->update([
                'author_email' => DB::raw('from_email'),
                'author_name' => DB::raw('from_name'),
            ]);
    }

    public function down(): void
    {
        Schema::table('help_center_messages', function (Blueprint $table) {
            $table->dropColumn(['author_email', 'author_name']);
        });
    }
};
