<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drafts (docs/features/drafts.md) — a work item captured before it has a project.
 *
 * Three columns stop being mandatory. `project_id` because a draft has no project; that is the
 * feature. `sequence_no` and `identifier` because the workspace-unique ID number is allocated
 * at publish rather than at capture — a draft that is never published must not burn an ID, or
 * the numbering the 2026_08_19 migration made gap-free grows gaps again.
 *
 * The uniques are `(tenant_id, sequence_no)` and `(tenant_id, identifier)`. MySQL permits
 * repeated NULLs in a unique index, so any number of drafts coexist under both without
 * colliding, and a published item is still unique across the workspace.
 *
 * The invariant the application maintains on top of this:
 *   is_draft = 1  ⇔  project_id IS NULL ∧ sequence_no IS NULL ∧ identifier IS NULL
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_items', function (Blueprint $table) {
            // Dropped before project_id can be made nullable — MySQL will not alter a column
            // an active foreign key constrains.
            $table->dropForeign(['project_id']);
        });

        Schema::table('work_items', function (Blueprint $table) {
            $table->boolean('is_draft')->default(false)->after('project_id');
            $table->foreignId('project_id')->nullable()->change();
            $table->unsignedInteger('sequence_no')->nullable()->change();
            $table->string('identifier', 24)->nullable()->change();
        });

        Schema::table('work_items', function (Blueprint $table) {
            // Restored with the same behaviour it had: deleting a project still takes its work
            // items with it. Drafts hold NULL here, so they are untouched by any project.
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            // The Drafts list: one author's drafts inside one workspace, most recently edited
            // first. Leads with tenant_id so it also covers that foreign key.
            $table->index(['tenant_id', 'created_by', 'is_draft', 'updated_at'], 'work_items_drafts_index');
        });
    }

    public function down(): void
    {
        // Drafts cannot be represented once the columns are mandatory again, and they are
        // scratch notes with nothing referencing them — so they go rather than block the
        // rollback with a NOT NULL violation.
        DB::table('work_items')->where('is_draft', true)->delete();

        Schema::table('work_items', function (Blueprint $table) {
            $table->dropIndex('work_items_drafts_index');
            $table->dropForeign(['project_id']);
        });

        Schema::table('work_items', function (Blueprint $table) {
            $table->dropColumn('is_draft');
            $table->foreignId('project_id')->nullable(false)->change();
            $table->unsignedInteger('sequence_no')->nullable(false)->change();
            $table->string('identifier', 24)->nullable(false)->change();
        });

        Schema::table('work_items', function (Blueprint $table) {
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
        });
    }
};
