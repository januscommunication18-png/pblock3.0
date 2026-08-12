<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * project_pages.status — Draft or Published (Pages §9).
 *
 * §9 asks for a "page status" without naming the values; the product answer is Draft and
 * Published. A new page starts as a **draft**: documentation is written before it is ready to
 * be read, and defaulting to published would announce every half-finished note.
 *
 * This is separate from `archived_at`, which answers a different question — draft/published is
 * "is this ready?", archived is "is this still current?". A published page can be archived
 * without becoming a draft again, so collapsing them into one column would lose information.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_pages', function (Blueprint $table) {
            $table->string('status', 20)->default('draft')->after('content');
            $table->index(['project_id', 'status']);
        });

        // Pages that already exist were written before there was a draft state, and their
        // authors treated them as live — so they stay live rather than silently reverting to
        // drafts nobody meant to hide.
        DB::table('project_pages')->update(['status' => 'published']);
    }

    public function down(): void
    {
        Schema::table('project_pages', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'status']);
            $table->dropColumn('status');
        });
    }
};
