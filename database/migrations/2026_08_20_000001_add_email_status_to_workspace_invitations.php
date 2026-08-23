<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record what DELIVERY did to an invitation (docs/features/help-center.md, P10).
 *
 * An invitation row said "pending" whether the email had been read, ignored, or rejected by the
 * recipient's mail server ten seconds after sending. Two invitations in a row were lost to typos
 * in the address — one to a nonexistent mailbox, one to a lookalike domain — and in both cases
 * the screen went on showing "Invited" indefinitely while Postmark had known within seconds.
 *
 * Sending cannot report this: Postmark accepts the SMTP transaction and only then decides, so
 * the send genuinely succeeds from the application's side. The answer has to come back later,
 * which is what the bounce webhook is for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_invitations', function (Blueprint $table) {
            // null = nothing heard back, which is the normal case for a delivered invitation.
            // Otherwise Postmark's own bounce type: HardBounce, SpamComplaint, SMTPApiError…
            $table->string('email_status', 40)->nullable()->after('status');

            // The human-readable reason, shown on the Members grid so the person who typed the
            // address can see WHY it failed rather than only that it did.
            $table->text('email_error')->nullable()->after('email_status');

            $table->timestamp('email_failed_at')->nullable()->after('email_error');
        });
    }

    public function down(): void
    {
        Schema::table('workspace_invitations', function (Blueprint $table) {
            $table->dropColumn(['email_status', 'email_error', 'email_failed_at']);
        });
    }
};
