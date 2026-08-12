<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * epics (Epic §20) — a larger initiative that work items contribute to.
 *
 * TENANT-SCOPED (CLAUDE.md §7) *and* project-scoped: `tenant_id` for the BelongsToTenant
 * global scope plus `project_id`, because §5 gives each Epic exactly one Project and §21
 * requires the API to confirm an Epic belongs to the same Project as the work item.
 *
 * `status` is stored, like a module's and unlike a cycle's derived one: §5's six values
 * include Paused and Cancelled, statements of intent that no date can express.
 *
 * `identifier` is a plain per-project counter rather than §5's `WEB-E01`, so an Epic reads the
 * way every other numbered record in this app already does — see docs/features/epics.md (E1).
 * §6 only requires it to be unique within the project, which the composite unique enforces.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('epics', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->unsignedInteger('identifier');
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('backlog');
            $table->string('priority', 20)->default('none');
            $table->date('start_date')->nullable();
            $table->date('target_date')->nullable();
            $table->foreignId('lead_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            // §16 keeps an Epic's history meaningful after removal, as Modules do.
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            // §6: the ID is unique within the Project, not the workspace.
            $table->unique(['project_id', 'identifier']);
            // Drives the list: a project's active epics, newest first.
            $table->index(['project_id', 'archived_at']);
            $table->index(['project_id', 'status']);
        });

        // §20: epic members are involvement, not assignment — being on an Epic gives you no
        // work, and removing you from it takes none away.
        Schema::create('epic_members', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('epic_id')->constrained('epics')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['epic_id', 'user_id']);
        });

        /*
         * §8/§22: the Epic's own activity feed. A separate table from `work_item_activity`
         * rather than a polymorphic one — the two record different vocabularies (an epic has
         * no state or assignee; a work item has no lead or member), and a shared table would
         * have to be nullable in every column that distinguishes them.
         *
         * Append-only by intent: rows are written once and never updated, so the feed is a
         * record of what happened rather than a mutable summary.
         */
        Schema::create('epic_activity', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('epic_id')->constrained('epics')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 40);
            $table->string('field', 40)->nullable();
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['epic_id', 'id']);
        });

        /*
         * §9: ONE epic per work item in Phase 1, so this is a column like `cycle_id` rather
         * than a pivot like modules'.
         *
         * nullOnDelete is a BACKSTOP, not the mechanism. Epics soft-delete so their activity
         * and audit trail survive (§22), so the row stays in the table and this constraint
         * never fires on an ordinary delete — EpicController::destroy clears the column itself.
         * The constraint still matters for a hard delete or a purge: §16 says every work item
         * survives its epic, and nothing should be able to leave one pointing at a row that
         * is gone.
         */
        Schema::table('work_items', function (Blueprint $table) {
            $table->foreignId('epic_id')->nullable()->after('cycle_assigned_at')
                ->constrained('epics')->nullOnDelete();
            $table->index(['project_id', 'epic_id']);
        });
    }

    public function down(): void
    {
        Schema::table('work_items', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'epic_id']);
            $table->dropConstrainedForeignId('epic_id');
        });

        Schema::dropIfExists('epic_activity');
        Schema::dropIfExists('epic_members');
        Schema::dropIfExists('epics');
    }
};
