<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * work_item_links (Collaboration spec §37–§41) — external URLs attached to a work item:
 * a Figma file, a doc, a GitHub PR, a support ticket.
 *
 * TENANT-SCOPED (CLAUDE.md §7). The URL is validated and scheme-restricted on write (§39);
 * the title is optional and falls back to the link's domain when rendering, so a link always
 * has something readable to click.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_item_links', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('work_item_id')->constrained('work_items')->cascadeOnDelete();
            $table->text('url');
            $table->string('title')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['work_item_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_item_links');
    }
};
