<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * workspace_memberships (spec §8) — the authorization plumbing that links a central
 * user identity to a workspace (tenant) with a role.
 *
 * This is a CENTRAL table, deliberately NOT tenant-scoped: it must be queried across
 * all tenants to power the workspace switcher ("your workspaces") and to authorize
 * access to a workspace before tenancy is initialized. See CLAUDE.md §18 D6.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_memberships', function (Blueprint $table) {
            $table->id();
            $table->string('workspace_id'); // -> tenants.id (UUID)
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20);              // owner | admin | member | viewer | guest
            $table->string('status', 20)->default('active'); // active | invited | suspended
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['workspace_id', 'user_id']); // one membership per user per workspace
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_memberships');
    }
};
