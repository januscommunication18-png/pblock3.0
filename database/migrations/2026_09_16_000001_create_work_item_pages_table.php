<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * work_item_pages — the documentation a work item points at.
 *
 * Many-to-many both ways: a work item can cite several pages (a spec, a decision record, the
 * meeting where it was agreed) and one page is naturally cited by many work items. That is
 * the whole reason it is a link rather than a column.
 *
 * Both sides cascade. Unlike a Module or an Epic, this relationship has no meaning once either
 * end is gone — a link to a deleted page is not history worth keeping, it is a dead row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_item_pages', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('work_item_id')->constrained('work_items')->cascadeOnDelete();
            $table->foreignId('project_page_id')->constrained('project_pages')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            // Linking the same page twice is a no-op, not a second row.
            $table->unique(['work_item_id', 'project_page_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_item_pages');
    }
};
