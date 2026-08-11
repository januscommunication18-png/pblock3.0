<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Project roles become admin / contributor / commenter / guest (Project Member Management
 * §3/§25). The only value in use before this was `member`, which becomes `contributor` —
 * the same "normal active project team member" the spec describes (§3).
 *
 * A data migration rather than a schema one: `role` is already a plain string column, so
 * only the values and the default need moving.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('project_members')->where('role', 'member')->update(['role' => 'contributor']);

        // The column default still said `member`, which is no longer a valid project role.
        // Schema builder, not raw ALTER, so this runs on SQLite too (CLAUDE.md §6).
        Schema::table('project_members', function (Blueprint $table) {
            $table->string('role', 20)->default('contributor')->change();
        });
    }

    public function down(): void
    {
        DB::table('project_members')->where('role', 'contributor')->update(['role' => 'member']);

        Schema::table('project_members', function (Blueprint $table) {
            $table->string('role', 20)->default('member')->change();
        });
    }
};
