<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Auto BCC holds MANY addresses (docs/features/help-center.md, P13).
 *
 * `auto_bcc_email` was one string, so a Space could blind-copy one archive mailbox. Teams that
 * want a compliance archive AND a shared mailbox had to choose.
 *
 * The single column is REPLACED rather than kept alongside the list. Two columns describing the
 * same setting is two answers to "where does Auto BCC send?", and the first reader to pick the
 * wrong one ships a bug that only shows up in somebody's mail.
 *
 * A JSON column rather than a table: the list belongs to exactly one settings row, is read and
 * written whole, is capped at `help-center.auto_bcc_max`, and nothing joins to it. A table would
 * add a migration, a model and a relationship to store what is a field.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('help_center_space_settings', function (Blueprint $table) {
            $table->json('auto_bcc_emails')->nullable()->after('auto_bcc_enabled');
        });

        // Carry every configured address across before the column holding it goes. Written
        // row by row because the value is JSON built from a string — not something a single
        // portable UPDATE expresses across MySQL and Postgres (D1).
        DB::table('help_center_space_settings')
            ->whereNotNull('auto_bcc_email')
            ->where('auto_bcc_email', '!=', '')
            ->orderBy('id')
            ->each(function (object $row) {
                DB::table('help_center_space_settings')
                    ->where('id', $row->id)
                    ->update(['auto_bcc_emails' => json_encode([$row->auto_bcc_email])]);
            });

        Schema::table('help_center_space_settings', function (Blueprint $table) {
            $table->dropColumn('auto_bcc_email');
        });
    }

    public function down(): void
    {
        Schema::table('help_center_space_settings', function (Blueprint $table) {
            $table->string('auto_bcc_email')->nullable()->after('auto_bcc_enabled');
        });

        // The FIRST address only — one column cannot hold a list, and rolling back is not the
        // moment to decide which of somebody's three archives matters most.
        DB::table('help_center_space_settings')
            ->whereNotNull('auto_bcc_emails')
            ->orderBy('id')
            ->each(function (object $row) {
                $emails = json_decode((string) $row->auto_bcc_emails, true);

                if (is_array($emails) && $emails !== []) {
                    DB::table('help_center_space_settings')
                        ->where('id', $row->id)
                        ->update(['auto_bcc_email' => (string) $emails[0]]);
                }
            });

        Schema::table('help_center_space_settings', function (Blueprint $table) {
            $table->dropColumn('auto_bcc_emails');
        });
    }
};
