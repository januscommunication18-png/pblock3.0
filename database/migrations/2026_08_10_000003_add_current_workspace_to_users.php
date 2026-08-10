<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks the user's active / last-active workspace (spec WS-009). Set when a workspace
 * is created and used to resume the user into the right workspace context on login.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'current_workspace_id')) {
                $table->string('current_workspace_id')->nullable()->after('timezone');
                $table->foreign('current_workspace_id')->references('id')->on('tenants')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'current_workspace_id')) {
                $table->dropForeign(['current_workspace_id']);
                $table->dropColumn('current_workspace_id');
            }
        });
    }
};
