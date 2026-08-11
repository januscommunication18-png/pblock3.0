<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-project work-item counter (Phase 5 / WI-006). Work item IDs are sequential *within a
 * project* — TESTI-1, TESTI-2 — so each project owns its own counter rather than sharing a
 * global sequence. Incremented under a row lock when a work item is created, which keeps
 * the numbering gap-free under concurrent creates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->unsignedInteger('work_item_sequence')->default(0)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('work_item_sequence');
        });
    }
};
