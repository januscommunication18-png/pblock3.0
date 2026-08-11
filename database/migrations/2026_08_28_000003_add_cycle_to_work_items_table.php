<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The work item ⇄ cycle relationship (Cycles §8.3 / §16).
 *
 * A nullable FK on the work item rather than the relationship table §16 suggests: "a work
 * item belongs to at most one cycle" then becomes unrepresentable rather than merely
 * forbidden, and moving between cycles is one UPDATE instead of a delete-then-insert that can
 * half-fail.
 *
 * `nullOnDelete`, not cascade: §8.3.7 says removing the cycle must not delete the work item,
 * and that has to hold when the removal is the cycle's own deletion.
 *
 * `cycle_assigned_by` / `cycle_assigned_at` are §16's audit fields. They record the LAST
 * assignment; the full move history lives in work_item_activity, written in the same
 * transaction by WorkItemUpdater.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_items', function (Blueprint $table) {
            $table->foreignId('cycle_id')->nullable()->after('parent_id')->constrained('cycles')->nullOnDelete();
            $table->foreignId('cycle_assigned_by')->nullable()->after('cycle_id')->constrained('users')->nullOnDelete();
            $table->timestamp('cycle_assigned_at')->nullable()->after('cycle_assigned_by');
        });
    }

    public function down(): void
    {
        Schema::table('work_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cycle_assigned_by');
            $table->dropConstrainedForeignId('cycle_id');
            $table->dropColumn('cycle_assigned_at');
        });
    }
};
