<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Help Desk enablement (docs/features/help-desk.md, FR-1.1).
 *
 * One boolean beside the other app flags, because that is what an app being switched on IS in
 * this codebase — `WorkspaceApps` maps an app key to its column, and the create form, Settings
 * → General and the sidebar all read the answer through it.
 *
 * Default FALSE, like `wiki_enabled`: a workspace opts into Help Desk, it is not something it
 * arrives with. Disabling only flips this back — no Help Desk data is touched, which is what
 * the phase's fourth acceptance criterion requires.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_settings', function (Blueprint $table) {
            $table->boolean('help_desk_enabled')->default(false)->after('wiki_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('workspace_settings', function (Blueprint $table) {
            $table->dropColumn('help_desk_enabled');
        });
    }
};
