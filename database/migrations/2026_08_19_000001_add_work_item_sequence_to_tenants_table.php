<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-workspace work-item counter (Phase 5 / WI-006, revised).
 *
 * Work item IDs are now a plain unique number rather than `<PROJECT>-<n>`, and that number is
 * unique across the whole workspace — so the counter moves from `projects` to the tenant row.
 * One counter per workspace means one row to lock per allocation, which keeps the numbering
 * gap-free under concurrent creates in different projects.
 *
 * The column is a real column on `tenants` (not the stancl `data` JSON overflow), so it is
 * also declared in Workspace::getCustomColumns().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->unsignedInteger('work_item_sequence')->default(0)->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('work_item_sequence');
        });
    }
};
