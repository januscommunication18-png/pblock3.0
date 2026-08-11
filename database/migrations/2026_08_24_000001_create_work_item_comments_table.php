<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * work_item_comments (Activity & Audit spec §7.13) — the discussion on a work item.
 *
 * TENANT-SCOPED (CLAUDE.md §7) and project-stamped. `parent_comment_id` carries replies
 * rather than a separate table (§7.13), and the column is deliberately only ONE level deep
 * in the service layer — a reply cannot itself be replied to (§7.8).
 *
 * Soft-deleted (§7.7): a comment is removed from view but the audit trail that references it
 * stays truthful.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_item_comments', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('work_item_id')->constrained('work_items')->cascadeOnDelete();
            $table->foreignId('parent_comment_id')->nullable()->constrained('work_item_comments')->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->longText('content');
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['work_item_id', 'created_at']); // §23
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_item_comments');
    }
};
