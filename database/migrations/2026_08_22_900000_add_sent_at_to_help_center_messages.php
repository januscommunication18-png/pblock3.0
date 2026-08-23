<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Separating "when they say they sent it" from "when we got it"
 * (docs/features/help-center.md, P70).
 *
 * `received_at` was being filled from the sender's `Date:` header — a value written by the
 * customer's own mail client, from the customer's own clock. The header that exposed this was
 * exactly four hours behind, which produced two visible faults on #000009:
 *
 *   - the Inbox reported the ticket had been waiting 6 h 51 m when the ticket itself was
 *     2 h 58 m old, which is not a slow answer but an impossible one; and
 *   - the timeline showed the customer's reply ABOVE the agent's reply it came after,
 *     because the rows sort on that same borrowed timestamp.
 *
 * So `received_at` becomes OUR observation and `sent_at` keeps the sender's claim. Every clock
 * in the module — the waiting period, the queue order, last activity — measures our own
 * responsiveness, and none of them may be built from a number a stranger supplied.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('help_center_messages', function (Blueprint $table) {
            // Nullable: an outbound reply has no sender's claim to keep, and a message whose
            // Date header was unparseable has none either.
            $table->timestamp('sent_at')->nullable()->after('received_at');
        });

        /*
         * The correction, for rows already stored.
         *
         * `created_at` is when the INSERT happened, which for an inbound webhook is within
         * seconds of delivery — so it is the best record we have of when the message actually
         * reached us, and it is a fact this application observed.
         */
        DB::table('help_center_messages')
            ->where('direction', 'inbound')
            ->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('help_center_messages')->where('id', $row->id)->update([
                        'sent_at' => $row->received_at,
                        'received_at' => $row->created_at,
                    ]);
                }
            });

        /*
         * And the Requests whose clocks were built from those numbers.
         *
         * Only the IMPOSSIBLE ones are touched — a waiting period that starts before the ticket
         * exists. A ticket that has genuinely been waiting three days is left exactly as it is;
         * this is a correction, not a reset of everybody's queue.
         */
        DB::table('help_center_requests')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                $newest = DB::table('help_center_messages')
                    ->where('help_center_request_id', $row->id)
                    ->orderByDesc('received_at')->orderByDesc('id')
                    ->first(['received_at', 'direction']);

                if ($newest === null) {
                    continue;
                }

                $update = [];

                if ($row->last_message_at !== null && $row->last_message_at < $row->created_at) {
                    $update['last_message_at'] = $newest->received_at;
                }

                /*
                 * The wait restarts at the last CUSTOMER message, which is the moment the
                 * answer became owed. When the last message is ours, the customer is the one
                 * holding the conversation and the clock is left alone.
                 */
                if ($row->waiting_since !== null && $row->waiting_since < $row->created_at) {
                    $update['waiting_since'] = $newest->direction === 'inbound'
                        ? $newest->received_at
                        : $row->created_at;
                }

                if ($update !== []) {
                    DB::table('help_center_requests')->where('id', $row->id)->update($update);
                }
            }
        });
    }

    public function down(): void
    {
        DB::table('help_center_messages')
            ->whereNotNull('sent_at')
            ->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('help_center_messages')->where('id', $row->id)
                        ->update(['received_at' => $row->sent_at]);
                }
            });

        Schema::table('help_center_messages', function (Blueprint $table) {
            $table->dropColumn('sent_at');
        });
    }
};
