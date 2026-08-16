<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settings > Security: how long a session may sit idle (docs/features/session-timeout.md).
 *
 * Nullable, with no default: null means "whatever the application default is", so existing
 * workspaces need no backfill and the default stays changeable in one place afterwards. A
 * column defaulted to 30 would freeze today's answer into every row ever created.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('session_timeout_minutes')->nullable()->after('wiki_docs_url');
        });
    }

    public function down(): void
    {
        Schema::table('workspace_settings', function (Blueprint $table) {
            $table->dropColumn('session_timeout_minutes');
        });
    }
};
