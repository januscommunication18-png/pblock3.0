<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * work_item_updates (Activity & Audit spec §8.10) — structured status reporting:
 * On Track / At Risk / Off Track, with the sub-task progress AT THE TIME OF WRITING.
 *
 * The progress snapshot is stored rather than recomputed on read (§8.6): an update is a
 * statement about how things stood that day. Recalculating it later would silently rewrite
 * what someone reported.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_item_updates', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('work_item_id')->constrained('work_items')->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20); // on_track | at_risk | off_track
            $table->longText('content');
            $table->unsignedTinyInteger('progress_percent')->nullable();
            $table->unsignedInteger('completed_subtasks')->nullable();
            $table->unsignedInteger('total_subtasks')->nullable();
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['work_item_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_item_updates');
    }
};
