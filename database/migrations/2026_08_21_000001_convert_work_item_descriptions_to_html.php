<?php

use App\Services\RichTextSanitizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Work item descriptions become rich text (SunEditor).
 *
 * Everything written before this was literal plain text and was rendered escaped. Rendering
 * those same rows as HTML would lose their line breaks and — worse — start interpreting any
 * angle brackets their authors typed. So each existing description is converted once:
 * escaped, line breaks turned into markup, wrapped in paragraphs.
 *
 * Rows that already look like markup are left alone: this migration is re-runnable after a
 * rollback without double-escaping content the editor itself produced.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sanitizer = app(RichTextSanitizer::class);

        DB::table('work_items')
            ->whereNotNull('description')
            ->where('description', '<>', '')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($sanitizer) {
                foreach ($rows as $row) {
                    if (preg_match('/<(p|div|ul|ol|h[1-6]|blockquote|table|br)\b/i', $row->description)) {
                        continue; // already HTML
                    }

                    DB::table('work_items')->where('id', $row->id)
                        ->update(['description' => $sanitizer->fromPlainText($row->description)]);
                }
            });
    }

    public function down(): void
    {
        $sanitizer = app(RichTextSanitizer::class);

        // Back to plain text. Formatting cannot survive the round trip — the text does.
        DB::table('work_items')
            ->whereNotNull('description')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($sanitizer) {
                foreach ($rows as $row) {
                    DB::table('work_items')->where('id', $row->id)
                        ->update(['description' => $sanitizer->excerpt($row->description, 20000)]);
                }
            });
    }
};
