<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * project_pages (Pages §8/§17) — project documentation.
 *
 * TENANT-SCOPED (CLAUDE.md §7) *and* project-scoped: §4.6 keeps every page created under a
 * project scoped to that project, and AC-03/AC-04 make the setting itself per-project.
 *
 * `parent_id` is here in Phase 1 even though the UI does not nest yet. §9 lists a parent page
 * "when applicable" and §14 asks that pages be able to join the Wiki hierarchy later *without
 * migrating or recreating content* — a self-reference costs nothing now and is the one thing
 * that would be expensive to add once pages exist in the wild.
 *
 * `archived_at` gives §9's "page status" an honest vocabulary — Active or Archived — rather
 * than inventing a draft/published lifecycle the spec never defines.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_pages', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('title', 200);
            // Rich text (§10), sanitized on the way in by RichTextSanitizer.
            $table->longText('content')->nullable();
            // Nested pages (§14). nullOnDelete, not cascade: deleting a parent must not take
            // its children's content with it — §5's principle is disable, never destroy, and
            // the same instinct applies to a delete that was only meant to remove one page.
            $table->foreignId('parent_id')->nullable()->constrained('project_pages')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // §8: updated-by is recorded automatically, so the list can say who touched it last.
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            // §5: page history survives the feature being switched off, and a deleted page
            // keeps its trail too.
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            // Drives the list: a project's live pages, most recently updated first.
            $table->index(['project_id', 'archived_at', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_pages');
    }
};
