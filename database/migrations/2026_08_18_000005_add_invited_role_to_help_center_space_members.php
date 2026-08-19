<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The workspace role a coworker was invited with (docs/features/help-center.md, P2 §8, HC-D19).
 *
 * Step 2 asks for a role per coworker rather than defaulting everyone to `member`. The
 * authoritative record of an invitation's role is the `workspace_invitations` row, so this
 * column is NOT that — it is what was ASKED FOR, kept for the case where the two differ.
 *
 * They differ when an invitation is refused: WorkspaceInviter turns rows away for `no_seats`,
 * `already_invited` and `invalid_role`, and in each case the member row survives as pending with
 * no invitation attached. Without this column the intended role is lost at that moment, and
 * P2 §29 is explicit that a failure must not silently discard configuration.
 *
 * Nullable, because it means nothing for somebody who is already a workspace member — they have
 * a role already, and Step 2 is not the place to change it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('help_center_space_members', function (Blueprint $table) {
            $table->string('invited_role', 20)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('help_center_space_members', function (Blueprint $table) {
            $table->dropColumn('invited_role');
        });
    }
};
