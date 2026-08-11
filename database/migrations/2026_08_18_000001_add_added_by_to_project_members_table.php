<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * project_members.added_by (Project Member Management §25) — who put this person on the
 * project, surfaced as the "Added By" column on the Members screen (§6).
 *
 * Nullable: rows created before this migration have no recorded actor, and the column
 * survives the actor being deleted rather than taking the membership with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_members', function (Blueprint $table) {
            $table->foreignId('added_by')->nullable()->after('user_id')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('project_members', function (Blueprint $table) {
            $table->dropConstrainedForeignId('added_by');
        });
    }
};
