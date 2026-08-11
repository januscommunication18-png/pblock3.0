<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * work_item_assignees (Work Items §8) — many-to-many, per the requirements doc; the POC row
 * only renders one avatar but the model must not have to change to show more.
 *
 * No `tenant_id` here on purpose: this is a pure join table whose rows are only ever reached
 * through a `work_items` row, which is itself tenant-scoped. Adding a tenant column to a
 * BelongsToMany pivot would need a tenant-aware pivot model without buying any isolation the
 * parent scope does not already provide.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_item_assignees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_item_id')->constrained('work_items')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['work_item_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_item_assignees');
    }
};
