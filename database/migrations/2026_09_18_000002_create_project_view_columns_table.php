<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * project_view_columns (Views §22.2) — one row per column mapped into a View.
 *
 * `position_type` is what makes §8's two-card model a data question rather than a rendering
 * trick: Fixed columns pin left and Scroll columns move, and §7.2 derives the grid's frozen
 * count from how many Fixed columns there are. `sort_order` is ordered WITHIN a position type,
 * so dragging a column between cards changes both fields together.
 *
 * There is deliberately NO `is_available` column. §9.3 requires that a column whose feature is
 * later disabled keeps its configuration and returns when the feature comes back. Storing
 * availability would mean writing to every affected row on each feature toggle — and going
 * quietly stale the first time that write was missed. It is derived at read time from
 * ProjectFeatureState instead, so it cannot drift and disabling a feature writes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_view_columns', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('project_view_id')->constrained('project_views')->cascadeOnDelete();
            // §9.1's categories: work_item, epic, cycle, module, estimate, label, member.
            $table->string('source_type', 32);
            // The key into ViewFieldCatalog, which is the single definition of what a column
            // may be — validated against it on write, so an unknown field never reaches here.
            $table->string('source_field', 64);
            // §10's alias. Null means "use the catalog's label", so renaming a field centrally
            // still updates every View that did not deliberately override it.
            $table->string('display_name', 120)->nullable();
            $table->string('position_type', 8)->default('scroll');
            $table->unsignedInteger('sort_order')->default(0);
            // Null means the catalog's default width — the same reasoning as display_name.
            $table->unsignedInteger('width')->nullable();
            $table->boolean('is_visible')->default(true);
            // A CEILING, never a grant (§11.3): switching it on cannot make a read-only field
            // editable, nor authorise a user who lacks permission on the work item itself.
            $table->boolean('is_editable')->default(true);
            // §10's formatting options. Unused in Slice 1; here because adding a json column
            // later to a table with rows is the expensive kind of change.
            $table->json('formatting_json')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            // §8.3: a column can exist only once in a View.
            $table->unique(['project_view_id', 'source_type', 'source_field'], 'project_view_columns_unique_field');
            // The render order: Fixed first, then Scroll, each by sort_order (§8.3).
            $table->index(['project_view_id', 'position_type', 'sort_order'], 'project_view_columns_layout_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_view_columns');
    }
};
