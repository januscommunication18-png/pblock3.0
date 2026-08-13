<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The account profile's own fields (Account §1).
 *
 * `full_name` and `avatar_url` already exist and are what the whole app reads — displayName(),
 * initial(), every row avatar. Nothing here replaces them:
 *
 *   first_name / last_name  the name, as two fields, because the form asks for two. `full_name`
 *                           is kept in step from them on save, so every existing reader keeps
 *                           working without knowing this migration happened.
 *   display_name            what the user would rather be called, when that is not their name.
 *                           Optional; displayName() falls back to full_name exactly as before.
 *   cover_url               the profile banner. `avatar_url` already covers the profile image.
 *
 * Portable schema builder only (CLAUDE.md §6/D1) — no raw SQL, so a future Postgres move stays
 * cheap. Guarded with hasColumn for the same reason extend_users_table is: these migrations run
 * against databases that have been through several phases.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'first_name')) {
                $table->string('first_name')->nullable()->after('id');
            }
            if (! Schema::hasColumn('users', 'last_name')) {
                $table->string('last_name')->nullable()->after('first_name');
            }
            if (! Schema::hasColumn('users', 'display_name')) {
                $table->string('display_name')->nullable()->after('full_name');
            }
            if (! Schema::hasColumn('users', 'cover_url')) {
                $table->string('cover_url')->nullable()->after('avatar_url');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['first_name', 'last_name', 'display_name', 'cover_url'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
