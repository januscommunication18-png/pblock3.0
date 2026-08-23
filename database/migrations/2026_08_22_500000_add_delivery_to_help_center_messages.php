<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Did the reply actually go? (docs/features/help-center.md, P64)
 *
 * The requirement asks the ticket to show a delivery status and offer Retry on failure. Until now
 * a send that threw was logged and the agent was told once, in a toast — which is gone the moment
 * they look away, leaving a reply on the ticket that looks identical to one that arrived.
 *
 * That is the gap worth closing: "we stored your words" and "the customer has them" are different
 * facts, and only one of them was visible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('help_center_messages', function (Blueprint $table) {
            // sent | failed. NULL on inbound rows, which were never "delivered" by us — a status
            // column that claimed otherwise would be inventing a fact about the customer's server.
            $table->string('delivery_status', 12)->nullable()->after('received_at');
            // The provider's own words, kept for the agent rather than only for the log.
            $table->text('delivery_error')->nullable()->after('delivery_status');
            $table->timestamp('delivered_at')->nullable()->after('delivery_error');
            // What it was actually sent as, so the ticket can show it without re-deriving it from
            // a Space whose configuration may have changed since.
            $table->string('reply_to')->nullable()->after('delivered_at');
        });

        /*
         * Existing outbound rows are marked SENT.
         *
         * They were: the old code stored the message and then sent, and a throw was logged rather
         * than recorded. Leaving them null would render every historical reply as "unknown", which
         * reads like a fault where there is none.
         */
        DB::table('help_center_messages')
            ->where('direction', 'outbound')
            ->update(['delivery_status' => 'sent', 'delivered_at' => DB::raw('received_at')]);
    }

    public function down(): void
    {
        Schema::table('help_center_messages', function (Blueprint $table) {
            $table->dropColumn(['delivery_status', 'delivery_error', 'delivered_at', 'reply_to']);
        });
    }
};
