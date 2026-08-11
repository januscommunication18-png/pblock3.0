<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Project Settings → Features (PRJ-042; Cycles §3.2.1) — per-project capability flags.
 *
 * One JSON map rather than a row per project-feature: nothing asks "which projects have
 * cycles on", only "does THIS project", and the catalog itself lives in config. Absent keys
 * fall back to the catalog default, so a feature added later needs no backfill.
 *
 * Nullable with no default: MySQL/MariaDB reject a default on a JSON column, and null already
 * means "nothing set yet, use the defaults".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->json('features')->nullable()->after('work_item_view');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('features');
        });
    }
};
