<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Associates an invitation with the user it turned out to be (invite spec §11/§32).
 *
 * The link is recorded as soon as the identity is known — when the invited email verifies its
 * code — which is *before* onboarding finishes and before any membership exists. That is what
 * lets the join screen find "the invitation this user is here for" without a session key, and
 * survives the user finishing onboarding in a different browser.
 *
 * Nullable: an invitation that is never accepted never has a user.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_invitations', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('inviter_user_id')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('workspace_invitations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
