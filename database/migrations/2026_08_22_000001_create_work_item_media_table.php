<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * work_item_media — images uploaded from a work item's description editor.
 *
 * TENANT-SCOPED (CLAUDE.md §7) *and* project-scoped. It exists for two reasons the file
 * itself cannot serve: the editor's image gallery needs a list of what this project has
 * uploaded, and serving a file has to be authorized, which means resolving the file back to
 * the project it belongs to.
 *
 * Files live on the private disk and are streamed through an authorized route — never the
 * public disk. A public URL would make every image in a private project readable by anyone
 * who guessed or was forwarded the link, which is exactly the isolation §7 exists to prevent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_item_media', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            // Media is uploaded before the work item is saved (a new item has no id yet), so
            // the link is optional and filled in when it is known.
            $table->foreignId('work_item_id')->nullable()->constrained('work_items')->nullOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('disk', 32)->default('local');
            $table->string('path');
            $table->string('name');
            $table->string('mime', 128);
            $table->unsignedBigInteger('size');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            // The gallery reads a project's newest uploads.
            $table->index(['project_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_item_media');
    }
};
