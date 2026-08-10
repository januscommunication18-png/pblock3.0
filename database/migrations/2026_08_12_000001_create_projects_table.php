<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * projects (Phase 4 — Create Project, spec §5 / PRJ-002/020-028) — a project always lives
 * inside exactly one workspace. TENANT-SCOPED (BelongsToTenant): every query is confined to
 * the active workspace and `tenant_id` is stamped on insert.
 *
 * Identifier uniqueness is workspace-scoped (UNIQUE(tenant_id, identifier), PRJ-022): the
 * same identifier may exist in a different workspace. "Unlimited projects per workspace"
 * (PRJ-001) is a product entitlement — no application-level count cap; the (tenant_id,
 * status) index keeps active-list queries fast at scale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');                       // -> tenants.id (the workspace)
            $table->string('name');                            // PRJ-021 required
            $table->string('identifier', 12);                  // PRJ-022 uppercase A-Z/0-9 code
            $table->text('description')->nullable();           // PRJ-024 optional
            $table->string('visibility', 10)->default('public'); // PRJ-025 public | private
            $table->foreignId('lead_user_id')->nullable()->constrained('users')->nullOnDelete(); // PRJ-026
            $table->string('cover_url')->nullable();           // PRJ-027 optional uploaded cover
            $table->string('timezone', 64)->nullable();        // defaults from workspace (PRJ-040)
            $table->string('status', 20)->default('active');   // active | archived (lifecycle)
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete(); // auditability
            $table->timestamp('archived_at')->nullable();      // lifecycle timestamp (archive is a later phase)
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'identifier']);       // PRJ-022 workspace-scoped uniqueness
            $table->index(['tenant_id', 'status']);            // active-list pagination (NFR §8)
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
