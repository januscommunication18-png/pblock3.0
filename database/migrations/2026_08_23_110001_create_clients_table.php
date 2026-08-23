<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A SaaS client (docs/features/backoffice-clients.md, §3). CENTRAL.
 *
 * The entity above the tenant. `Workspace` extends stancl's `Tenant` and was the top of the tree
 * until now; a Client owns however many workspaces a company has, and the applications those
 * workspaces enable are what that company has subscribed to (BC-D1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();

            // `CL-000128` — the human-readable id §8 puts on the Overview tab. Stored rather than
            // derived from the auto-increment so it survives a future re-key, and unique so it can
            // be searched on (§4) without ambiguity.
            $table->string('code', 20)->unique();

            $table->string('name');

            /*
             * The primary contact (§8) — a CUSTOMER user, not a Back Office one.
             *
             * `nullOnDelete`: a client whose contact deleted their account is still a client, and
             * the Back Office needs to see it in order to fix it. Cascading would delete the
             * company because a person left.
             */
            $table->foreignId('primary_contact_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->string('phone', 40)->nullable();
            $table->string('country', 80)->nullable();
            $table->string('timezone', 64)->nullable();

            // One of Client::STATUSES (§21).
            $table->string('status', 20)->default('active');

            // When the switch was thrown, kept separately from `status` so the activity feed and
            // the header can say "disabled 3 days ago" without walking the activity table.
            $table->timestamp('disabled_at')->nullable();

            /*
             * Stage 1 of the two-stage delete (§19, BC-D4).
             *
             * The moment the retention window STARTED. Permanent removal is a later, deliberate
             * job that reads this column; the button never hard-deletes.
             */
            $table->timestamp('pending_deletion_at')->nullable();

            // Who created it from the Back Office. Null for the backfill, which nobody created.
            $table->foreignId('created_by')->nullable()
                ->constrained('backoffice_users')->nullOnDelete();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['status', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
