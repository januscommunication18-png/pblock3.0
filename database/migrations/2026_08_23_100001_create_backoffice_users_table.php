<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Back Office administrators (docs/features/backoffice-auth.md, §7–§8). CENTRAL.
 *
 * A SEPARATE table from `users`, not a flag on it (BO-D1). A flag would put every customer row
 * one bad query away from platform administration, and the two are genuinely different shapes:
 * a Back Office user has no workspace, no tenancy context and no onboarding. Separate tables
 * also mean the Back Office guard's provider physically cannot resolve a customer account,
 * which is the boundary §9 asks for expressed in the schema rather than in a check.
 *
 * NOT tenant-scoped, and never should be: this is the platform's own staff, above every tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backoffice_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();

            /*
             * NULLABLE, deliberately (BO-D5).
             *
             * §7 forbids a hard-coded password, so the seeded Super Admin starts without one and
             * must set it through the reset flow — the only path that proves they hold the
             * mailbox. A null hash cannot be brute-forced, and `Auth::attempt` cannot succeed
             * against it, so "no password yet" is a safe state rather than an open door.
             */
            $table->string('password_hash')->nullable();
            $table->timestamp('password_set_at')->nullable();

            // One of BackofficeUser::ROLES. A string rather than an enum column: the rule this
            // codebase already follows for every vocabulary (see help-center statuses).
            $table->string('role', 20);

            /*
             * Disabled, not deleted. An administrator who has left is not one who never existed
             * — the audit log points at their id — so the row has an off switch instead.
             */
            $table->boolean('is_active')->default(true);

            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();

            // Who created them. Null for the seeded Super Admin, which nobody created.
            $table->foreignId('created_by')->nullable()
                ->constrained('backoffice_users')->nullOnDelete();

            $table->rememberToken();
            $table->timestamps();

            // The two questions every authorization check asks, together.
            $table->index(['is_active', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backoffice_users');
    }
};
