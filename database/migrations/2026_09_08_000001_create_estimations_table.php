<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estimation (Estimation §33) — one active estimation system per project, and its values.
 *
 * TENANT-SCOPED (CLAUDE.md §7) *and* project-scoped, like every other project-owned record.
 *
 * There is deliberately NO `enabled` column here, though §33 suggests one. The switch lives in
 * the project's `features` map with Epics, Modules and Cycles, because §29 asks Estimation to
 * follow their disable pattern and that is where the pattern reads it from. A second `enabled`
 * would be a second answer to one question. What this row exists for is §25: the configuration
 * has to survive being switched off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_estimations', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            // points | category | time (§5).
            $table->string('type', 20);
            // Which ready-made list it started from — fibonacci, tshirt, standard, custom.
            // A record of origin, not a constraint: §10/§20 let the values be edited freely
            // afterwards, and an edited Fibonacci system is still the project's system.
            $table->string('template', 30);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            // §9/§36: ONE active system per project, enforced here rather than hoped for.
            $table->unique('project_id');
        });

        Schema::create('estimate_values', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('project_estimation_id')->constrained('project_estimations')->cascadeOnDelete();
            $table->string('label', 40);
            // Points fill `numeric_value`; time fills `duration_minutes`; a category fills
            // neither. §32's rollups need something summable, and a label is not it — XS
            // cannot be added to S, which is precisely why a category can only be counted.
            $table->decimal('numeric_value', 8, 2)->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            // §21: a value in use is ARCHIVED, never deleted. It leaves every picker and the
            // work items already carrying it keep reading correctly.
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['project_estimation_id', 'active', 'sort_order']);
        });

        /*
         * §33: one estimate per work item.
         *
         * nullOnDelete is a backstop only — values are archived rather than deleted, so this
         * should never fire. If a value ever IS hard-deleted, §25 still says the work item
         * survives it, and this is what makes that true rather than hoped for.
         */
        Schema::table('work_items', function (Blueprint $table) {
            $table->foreignId('estimate_value_id')->nullable()->after('epic_id')
                ->constrained('estimate_values')->nullOnDelete();
            $table->index(['project_id', 'estimate_value_id']);
        });
    }

    public function down(): void
    {
        Schema::table('work_items', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'estimate_value_id']);
            $table->dropConstrainedForeignId('estimate_value_id');
        });

        Schema::dropIfExists('estimate_values');
        Schema::dropIfExists('project_estimations');
    }
};
