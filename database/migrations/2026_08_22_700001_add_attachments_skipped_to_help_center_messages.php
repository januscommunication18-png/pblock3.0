<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many files on this email were NOT kept, and why (docs/features/help-center.md, P66).
 *
 * Without this, a refusal is invisible: an agent reads "see the attached installer", sees no
 * attachment, and cannot tell whether the customer forgot it, the parser broke, or the system
 * refused it on purpose. Three very different next actions, and the ticket has to say which.
 *
 * The REASONS are stored, not just a count, because "too large" and "blocked type" need
 * different replies to the customer — one is "please use a link", the other is "please zip it".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('help_center_messages', function (Blueprint $table) {
            $table->unsignedSmallInteger('attachments_skipped')->default(0)->after('body_html');
            // [{name, reason}] — a short list, and null when nothing was refused so the common
            // case stores nothing rather than an empty array everywhere.
            $table->json('attachments_skipped_detail')->nullable()->after('attachments_skipped');
        });
    }

    public function down(): void
    {
        Schema::table('help_center_messages', function (Blueprint $table) {
            $table->dropColumn(['attachments_skipped', 'attachments_skipped_detail']);
        });
    }
};
