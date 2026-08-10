<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * workspace_settings (spec §6-§11) — the per-workspace singleton holding every feature
 * toggle plus wiki text. TENANT-SCOPED via App\Models\WorkspaceSettings (BelongsToTenant):
 * once tenancy is initialized these rows are auto-confined to the active workspace
 * (CLAUDE.md §7). `teamspaces_locked_at` records the irreversible one-way enable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_settings', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->unique(); // -> tenants.id ; one settings row per workspace
            $table->boolean('project_states_enabled')->default(true);
            $table->boolean('releases_enabled')->default(true);
            $table->boolean('initiatives_enabled')->default(false);
            $table->boolean('teamspaces_enabled')->default(false);
            $table->boolean('customers_enabled')->default(true);
            $table->boolean('wiki_enabled')->default(false);
            $table->timestamp('teamspaces_locked_at')->nullable(); // one-way latch (spec §10)
            $table->text('wiki_description')->nullable();
            $table->string('wiki_docs_url')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_settings');
    }
};
