<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Work item IDs become a plain unique number (WI-006, revised).
 *
 * Before: `<PROJECT IDENTIFIER>-<n>`, with `n` counted per project (TESTI-1, WEB-1 …).
 * After:  `1`, `2`, `3` … counted per **workspace**, so an ID identifies exactly one work
 * item anywhere in the workspace without the project prefix to disambiguate it.
 *
 * Existing rows are renumbered in creation order (`id` ASC) per workspace, which preserves
 * the relative age ordering the old per-project numbering implied. Renumbering rewrites IDs,
 * so anything that quoted an old identifier now reads differently — the per-item URL is keyed
 * on the primary key, not the identifier, so links keep resolving.
 *
 * `projects.work_item_sequence` is dropped: the counter now lives on the tenant row.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Uniqueness moves from per-project to per-workspace. Dropped first: the old rows
        // collide under the new constraints until they have been renumbered.
        Schema::table('work_items', function (Blueprint $table) {
            $table->dropUnique(['project_id', 'sequence_no']);
            $table->dropUnique(['project_id', 'identifier']);
        });

        foreach (DB::table('work_items')->distinct()->pluck('tenant_id') as $tenantId) {
            $n = 0;
            $ids = DB::table('work_items')->where('tenant_id', $tenantId)->orderBy('id')->pluck('id');
            foreach ($ids as $id) {
                $n++;
                DB::table('work_items')->where('id', $id)->update([
                    'sequence_no' => $n,
                    'identifier' => (string) $n,
                ]);
            }
            // Park the high-water mark so the next created item continues the sequence.
            DB::table('tenants')->where('id', $tenantId)->update(['work_item_sequence' => $n]);
        }

        Schema::table('work_items', function (Blueprint $table) {
            $table->unique(['tenant_id', 'sequence_no']);
            $table->unique(['tenant_id', 'identifier']);
        });

        // Both uniques lead with tenant_id, so the standalone index down() adds to keep the
        // foreign key covered is redundant once they exist (re-migrate after a rollback).
        if (Schema::hasIndex('work_items', 'work_items_tenant_id_index')) {
            Schema::table('work_items', function (Blueprint $table) {
                $table->dropIndex(['tenant_id']);
            });
        }

        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('work_item_sequence');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->unsignedInteger('work_item_sequence')->default(0)->after('status');
        });

        // The tenant_id foreign key needs a tenant_id-leading index, and the two uniques
        // below are the only ones left providing it — MySQL refuses to drop the last one.
        Schema::table('work_items', function (Blueprint $table) {
            $table->index('tenant_id');
        });

        Schema::table('work_items', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'sequence_no']);
            $table->dropUnique(['tenant_id', 'identifier']);
        });

        // Back to per-project numbering: walk each project's items in creation order and
        // rebuild `<PROJECT>-<n>`, restoring that project's counter to its last number.
        $prefixes = DB::table('projects')->pluck('identifier', 'id');
        foreach (DB::table('work_items')->distinct()->pluck('project_id') as $projectId) {
            $n = 0;
            $ids = DB::table('work_items')->where('project_id', $projectId)->orderBy('id')->pluck('id');
            foreach ($ids as $id) {
                $n++;
                DB::table('work_items')->where('id', $id)->update([
                    'sequence_no' => $n,
                    'identifier' => strtoupper((string) ($prefixes[$projectId] ?? 'ITEM')).'-'.$n,
                ]);
            }
            DB::table('projects')->where('id', $projectId)->update(['work_item_sequence' => $n]);
        }

        Schema::table('work_items', function (Blueprint $table) {
            $table->unique(['project_id', 'sequence_no']);
            $table->unique(['project_id', 'identifier']);
        });

        DB::table('tenants')->update(['work_item_sequence' => 0]);
    }
};
