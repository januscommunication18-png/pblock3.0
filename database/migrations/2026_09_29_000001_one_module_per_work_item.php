<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A work item belongs to ONE module at a time
 * (docs/features/module-management.md — §9.3 reversed).
 *
 * §9.3 originally gave a work item several modules — "a functional module and a release module
 * at once" — which is why this is a pivot table rather than a `module_id` column. The product
 * decision is now one module at a time, the same shape as `cycle_id`, and independent of it: an
 * item may hold one cycle AND one module.
 *
 * The pivot stays. A unique index on `work_item_id` turns it into a one-to-many in practice,
 * which is the guarantee — an application rule alone would leave the door open to any future
 * writer that forgets it. Keeping the table also means reversing this decision later costs one
 * index rather than a data migration back out of a column.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->collapseDuplicates();

        Schema::table('module_work_items', function (Blueprint $table) {
            // The old pair index is now implied by this one and would be dead weight.
            $table->unique('work_item_id');
        });
    }

    /**
     * Any work item in more than one module keeps its FIRST module and loses the rest.
     *
     * First, not last: the earliest link is the original decision about where the work belongs,
     * and the later ones were only ever possible because the rule did not exist yet. Nothing is
     * guessed — the work item itself is untouched, only the extra links go.
     *
     * A no-op on every database checked before shipping this (153 links, none duplicated); it
     * exists because "no duplicates here" is not the same as "no duplicates anywhere".
     */
    private function collapseDuplicates(): void
    {
        $duplicated = DB::table('module_work_items')
            ->select('work_item_id', DB::raw('COUNT(*) as links'))
            ->groupBy('work_item_id')
            ->having('links', '>', 1)
            ->pluck('work_item_id');

        foreach ($duplicated as $workItemId) {
            $keep = DB::table('module_work_items')
                ->where('work_item_id', $workItemId)
                ->orderBy('id')
                ->value('id');

            DB::table('module_work_items')
                ->where('work_item_id', $workItemId)
                ->where('id', '!=', $keep)
                ->delete();
        }
    }

    public function down(): void
    {
        Schema::table('module_work_items', function (Blueprint $table) {
            $table->dropUnique(['work_item_id']);
        });
    }
};
