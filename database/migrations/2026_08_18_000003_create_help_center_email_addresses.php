<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The customer-facing addresses that route into an Inbox
 * (docs/features/help-center.md §6, §7, §11).
 *
 * These are the addresses customers already write to — support@, hello@, billing@. They are
 * never changed by this application (§10); what is configured is the FORWARDING from them to
 * the Inbox's generated inbound address. TENANT-SCOPED.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_center_email_addresses', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();

            $table->foreignId('help_center_inbox_id')
                ->constrained('help_center_inboxes')
                ->cascadeOnDelete();

            // Stored normalized: trimmed and lowercased before it ever reaches here (§7).
            $table->string('email');
            $table->string('name', 100)->nullable();

            // A key from config('help-center.address_statuses') — §11's four states. New
            // addresses start `pending` ("Setup Required"), because nothing has yet proved the
            // forwarding works.
            $table->string('status', 20)->default('pending');
            $table->timestamp('verified_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            /*
             * "Prevent duplicate addresses within the Workspace" and "confirm the address has
             * not already been assigned to another Inbox" (§7) — one rule, and this is it
             * (HC-D6).
             *
             * Scoped to the tenant, not global: two companies may each route their own
             * support@ address, and a constraint that stopped the second one would be tenancy
             * leaking through a unique index.
             */
            $table->unique(['tenant_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('help_center_email_addresses');
    }
};
