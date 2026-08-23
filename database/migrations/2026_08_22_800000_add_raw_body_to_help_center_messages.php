<?php

use App\Services\HelpCenter\Inbound\QuotedReplyStripper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The email exactly as it arrived, kept beside the cleaned version
 * (docs/features/help-center.md, P68).
 *
 * The requirement's "the system may retain the original raw inbound email separately… however,
 * raw email content must never be rendered directly inside the ticket conversation". `body_text`
 * and `body_html` become the CLEANED reply, which is what every screen already reads; these two
 * are the originals, which nothing renders.
 *
 * ## Why the bodies and not the whole MIME message
 *
 * The requirement offers `raw_email` or the original MIME file in object storage. Storing the
 * full MIME would mean a second, base64-inflated copy of every attachment — files this module
 * already stores properly and privately (P66) — so a 20 MB invoice would be kept twice, once as
 * a downloadable file and once as an unreadable string in a database row. The bodies are the
 * part that troubleshooting actually needs: they are what the parser ran on.
 *
 * ## The backfill is safe because it writes the raw copy FIRST
 *
 * Existing tickets carry the whole quoted thread — ticket #000009 is the one that prompted this.
 * They are cleaned in place, and every original is preserved in the same statement, so the
 * backfill is reversible from the data it just wrote rather than from a dump.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('help_center_messages', function (Blueprint $table) {
            $table->longText('raw_text')->nullable()->after('body_html');
            $table->longText('raw_html')->nullable()->after('raw_text');
            // Whether the parser was SURE. False means it fell back to storing the body as it
            // arrived; the requirement asks for that to be logged and never shown to anybody.
            $table->boolean('quote_stripped')->default(false)->after('raw_html');
        });

        $stripper = new QuotedReplyStripper;

        DB::table('help_center_messages')
            ->where('direction', 'inbound')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($stripper) {
                foreach ($rows as $row) {
                    $text = $stripper->text($row->body_text);
                    $html = $stripper->html($row->body_html);

                    $changed = $text['clean'] !== (string) $row->body_text
                        || $html['clean'] !== (string) $row->body_html;

                    if (! $changed) {
                        continue;
                    }

                    DB::table('help_center_messages')->where('id', $row->id)->update([
                        // The originals go down FIRST, in the same statement that overwrites
                        // them — there is no window in which the only copy is the cleaned one.
                        'raw_text' => $row->body_text,
                        'raw_html' => $row->body_html,
                        'body_text' => $text['clean'],
                        'body_html' => $html['clean'],
                        'quote_stripped' => $text['confident'] && $html['confident'],
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Put the originals back before the columns holding them are dropped.
        DB::table('help_center_messages')
            ->whereNotNull('raw_text')
            ->orWhereNotNull('raw_html')
            ->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('help_center_messages')->where('id', $row->id)->update([
                        'body_text' => $row->raw_text ?? $row->body_text,
                        'body_html' => $row->raw_html ?? $row->body_html,
                    ]);
                }
            });

        Schema::table('help_center_messages', function (Blueprint $table) {
            $table->dropColumn(['raw_text', 'raw_html', 'quote_stripped']);
        });
    }
};
