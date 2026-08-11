<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * work_item_worklogs (Activity & Audit spec §9.10) — time spent on a work item.
 *
 * Duration is stored as TOTAL MINUTES (§9.9). Hours-and-minutes is a display format; storing
 * it as two columns would put arithmetic (and its rounding bugs) into every sum, filter and
 * report that touches tracked time.
 *
 * `user_id` is who the time belongs to; `created_by` is who recorded it, which can differ
 * when a manager logs work for someone else (§9.4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_item_worklogs', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('work_item_id')->constrained('work_items')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('work_date');
            $table->unsignedInteger('minutes_logged');
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['work_item_id', 'work_date']); // §23
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_item_worklogs');
    }
};
