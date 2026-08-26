<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `accounts` — the OWNING entity above a workspace
 * (docs/features/tenant-workspace-ownership.md).
 *
 * The requirement calls this a Tenant. It cannot be called that here: in this codebase
 * `tenants` is already the WORKSPACE table — a Workspace IS the stancl tenant (CLAUDE.md §18
 * D5) — so a second entity named Tenant would collide with the table, the model, the Back
 * Office's "Tenants" tab and every docblock that already uses the word. `Account` is the same
 * concept under a name that is free: one per client who owns workspaces, and the thing a
 * subscription and usage will hang off when those are built.
 *
 * CENTRAL, deliberately not tenant-scoped: an account sits ABOVE workspaces, so it has to be
 * readable with no tenancy context — the same reason `workspace_memberships` is central
 * (CLAUDE.md §18 D6).
 *
 * Not to be confused with `clients` (docs/features/backoffice-clients.md), which is also one
 * row per user. A Client is the Back Office's administrative record of a PERSON and exists for
 * everybody who belongs to any workspace, invited-only users included. An Account exists only
 * once somebody OWNS a workspace, and answers a different question: whose workspaces are these?
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();

            /*
             * The owner (§2). Unique: §3 gives a user ONE account for the workspaces they own,
             * and the database refuses a second rather than trusting every future writer to
             * look one up first.
             *
             * Nullable + `nullOnDelete` so a deleted user does not take the account — and with
             * it the ownership record of live workspaces — down with them. MySQL allows many
             * NULLs in a unique index, so orphaned accounts do not collide with each other.
             */
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();

            // A snapshot label, for the case above where there is no user left to name it by.
            $table->string('name');
            $table->string('status', 20)->default('active'); // active | suspended | cancelled

            $table->timestamps();

            $table->unique('owner_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
