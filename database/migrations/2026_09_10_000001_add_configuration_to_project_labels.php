<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Project labels become a configured feature (Project-Level Labels §7, §11, §27).
 *
 * Two additions to a table that already existed:
 *
 *  - `description` (§7) — an optional note on how the label is meant to be used.
 *  - `archived_at` (§11) — the Active → Archived → Restored lifecycle. Archiving is preferable
 *    to deleting whenever historical work items need to keep their categorization: an archived
 *    label leaves the picker but stays on every item already carrying it.
 *
 * The enable switch itself is NOT here. It lives in the project's `features` map with Epics,
 * Modules, Cycles and Estimation, so §3's disable behaviour is the one already built rather
 * than a second implementation of it. §2 wants labels ON by default, which the catalog's
 * default expresses — and because `featureFlags()` merges catalog defaults over stored ones,
 * every existing project keeps its labels working with no backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_item_labels', function (Blueprint $table) {
            $table->string('description', 255)->nullable()->after('name');
            $table->timestamp('archived_at')->nullable()->after('position');
            // Drives the picker: a project's active labels, in their configured order.
            $table->index(['project_id', 'archived_at']);
        });
    }

    public function down(): void
    {
        Schema::table('project_item_labels', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'archived_at']);
            $table->dropColumn(['description', 'archived_at']);
        });
    }
};
