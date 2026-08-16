<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Work Capacity, phase 1 (docs/features/work-capacity.md).
 *
 * Dated after 2026_09_08 deliberately: `estimate_values` has to exist before a capacity figure
 * can be hung off it.
 *
 * Nothing here stores weekly capacity (CAP-D7). It is `hours_per_day × working_days`, derived
 * on read — a stored copy is a third source of truth that drifts from the two inputs that
 * define it.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * CAP-D1 — the one conversion mechanism.
         *
         * Points and sizes both answer "how many hours is this?" here. `time` values do not:
         * they already carry `duration_minutes`, and a second hour figure beside it would be a
         * second answer to the same question, free to disagree.
         *
         * NULLABLE, and null means UNMAPPED rather than zero (CAP-D2) — an unmapped estimate is
         * excluded from planned hours and counted separately, because treating unknown work as
         * no work is exactly how a capacity report comes to lie.
         */
        Schema::table('estimate_values', function (Blueprint $table) {
            $table->decimal('capacity_hours', 6, 2)->nullable()->after('duration_minutes');
        });

        /*
         * CAP-D3 — the snapshot.
         *
         * Written when the estimate is set or changed. Two things depend on it: reports stop
         * joining estimate_values per row, and §47 holds — changing L from 12h to 16h today
         * must not silently rewrite last quarter's report. Open work is re-snapshotted when the
         * mapping changes; completed work keeps the value it was planned with.
         */
        Schema::table('work_items', function (Blueprint $table) {
            $table->decimal('capacity_hours', 8, 2)->nullable()->after('estimate_value_id');
            // The report's hot path: assigned, dated, estimated work for a project.
            $table->index(['project_id', 'capacity_hours']);
        });

        Schema::table('workspace_settings', function (Blueprint $table) {
            $table->boolean('capacity_enabled')->default(false)->after('session_timeout_minutes');
            // Decimal, because 7.5-hour days are ordinary and an integer column would quietly
            // round a workspace's real working day to something it never agreed to (§13).
            $table->decimal('capacity_hours_per_day', 4, 2)->default(8);
            // ISO-8601 weekday numbers (1 = Monday). Stored as a list rather than seven boolean
            // columns so "which days" stays one question with one answer (§14).
            $table->json('capacity_working_days')->nullable();
            // §29. Percentages, stored as written — 90 means 90%.
            $table->unsignedSmallInteger('capacity_near_threshold')->default(90);
            $table->unsignedSmallInteger('capacity_over_threshold')->default(100);
            $table->unsignedSmallInteger('capacity_high_threshold')->default(110);
        });

        /*
         * §16 — per-member overrides.
         *
         * CAP-D8: the ABSENCE of a row is "use the workspace default". A row with a
         * `use_default` flag would let a workspace's default change without following the
         * people who are supposed to be following it.
         */
        Schema::create('member_capacities', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('hours_per_day', 4, 2);
            $table->json('working_days')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            // One override per person per workspace, enforced rather than hoped for.
            $table->unique(['tenant_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_capacities');

        Schema::table('workspace_settings', function (Blueprint $table) {
            $table->dropColumn([
                'capacity_enabled',
                'capacity_hours_per_day',
                'capacity_working_days',
                'capacity_near_threshold',
                'capacity_over_threshold',
                'capacity_high_threshold',
            ]);
        });

        Schema::table('work_items', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'capacity_hours']);
            $table->dropColumn('capacity_hours');
        });

        Schema::table('estimate_values', function (Blueprint $table) {
            $table->dropColumn('capacity_hours');
        });
    }
};
