<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Media uploaded from a DRAFT's description editor (docs/features/drafts.md).
 *
 * A draft has no project, so neither does an image dropped into one — `project_id` stops being
 * mandatory for the same reason it did on `work_items` itself. The row stays tenant-scoped, so
 * an image can still never be read outside the workspace that owns it (CLAUDE.md §7).
 *
 * A project-less row is reachable only by its uploader. Publishing the draft re-homes the
 * images its description actually references onto the target project, at which point everyone
 * who can see that project's work items can see them — see WorkItemCreator::publish().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_item_media', function (Blueprint $table) {
            // Dropped before the column can be altered — MySQL will not change a column an
            // active foreign key constrains.
            $table->dropForeign(['project_id']);
        });

        Schema::table('work_item_media', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->change();
        });

        Schema::table('work_item_media', function (Blueprint $table) {
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            // The draft gallery reads one author's own project-less uploads, newest first.
            $table->index(['tenant_id', 'uploaded_by', 'project_id'], 'work_item_media_drafts_index');
        });
    }

    public function down(): void
    {
        // Project-less rows cannot be represented once the column is mandatory again. The files
        // they point at are unreferenced draft images; the drafts rollback discards those too.
        Schema::table('work_item_media', function (Blueprint $table) {
            $table->dropIndex('work_item_media_drafts_index');
            $table->dropForeign(['project_id']);
        });

        DB::table('work_item_media')->whereNull('project_id')->delete();

        Schema::table('work_item_media', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable(false)->change();
        });

        Schema::table('work_item_media', function (Blueprint $table) {
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
        });
    }
};
