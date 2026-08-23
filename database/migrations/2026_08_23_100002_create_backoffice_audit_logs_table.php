<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Back Office security events (docs/features/backoffice-auth.md, §11). CENTRAL.
 *
 * Every event in the requirement's list, success AND failure, with who/what/where/when.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backoffice_audit_logs', function (Blueprint $table) {
            $table->id();

            /*
             * NULLABLE, and that is the interesting case.
             *
             * The events most worth auditing have no user to point at: a code requested for an
             * address that is not authorized, a login attempt against an unknown email. Requiring
             * a user id would mean the log recorded only the events that went right.
             *
             * `nullOnDelete` rather than cascade: deleting an administrator must not erase what
             * they did. The email below survives them.
             */
            $table->foreignId('backoffice_user_id')->nullable()
                ->constrained('backoffice_users')->nullOnDelete();

            // The address AS TYPED. Not derived from the user, because on a failure there is no
            // user — and "who did somebody try to be?" is the question a failure log answers.
            $table->string('email')->nullable();

            // One of BackofficeAuditLog::ACTION_* — the requirement's own vocabulary.
            $table->string('action', 60);

            // 45 chars holds an IPv6 address with an IPv4 tail.
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->boolean('succeeded')->default(true);

            // Anything the event carries that is not one of the columns above — a role change's
            // before and after, a lockout's remaining seconds. JSON because nothing joins to it.
            $table->json('meta')->nullable();

            /*
             * `created_at` only. An audit row is a statement about a moment and is never edited,
             * so an `updated_at` would be a column that must always equal the one beside it —
             * and a column that CAN be updated is an invitation to update it.
             */
            $table->timestamp('created_at')->useCurrent();

            $table->index(['action', 'created_at']);
            $table->index(['email', 'created_at']);
            $table->index(['backoffice_user_id', 'created_at'], 'bo_audit_user_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backoffice_audit_logs');
    }
};
