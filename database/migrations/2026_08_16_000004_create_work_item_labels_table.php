<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * work_item_labels (Work Items §8) — many-to-many against the project's own label set
 * (`project_item_labels`), so a work item can only ever wear labels configured for its
 * project. Pure join table; see the assignees migration for why it carries no tenant_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_item_labels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_item_id')->constrained('work_items')->cascadeOnDelete();
            $table->foreignId('label_id')->constrained('project_item_labels')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['work_item_id', 'label_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_item_labels');
    }
};
