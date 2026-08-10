<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * workspace_invitations (spec §6/§8) — pending teammate invitations for a workspace.
 *
 * This is a TENANT-SCOPED table: the `tenant_id` column + the BelongsToTenant global
 * scope on App\Models\WorkspaceInvitation mean that, once tenancy is initialized to a
 * workspace, invitation queries are automatically confined to that workspace (CLAUDE.md
 * §7). The token is stored hashed; global token lookup (accept flow, later phase) uses
 * the `withoutTenancy` scope macro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_invitations', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id'); // -> tenants.id ; BelongsToTenant default column
            $table->string('email');
            $table->string('role', 20); // admin | member | viewer | guest (never owner)
            $table->foreignId('inviter_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('token', 64)->unique();          // sha-256 hash of the raw invite token
            $table->string('status', 20)->default('pending'); // pending | accepted | revoked | expired
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            // Fast lookups for the per-workspace pending list and duplicate-invite checks (INV-004).
            $table->index(['tenant_id', 'email', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_invitations');
    }
};
