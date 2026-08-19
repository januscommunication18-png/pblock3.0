<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Help Center Inboxes (docs/features/help-center.md §5, §8).
 *
 * An Inbox is where incoming customer conversations are delivered, and a Space may hold several
 * (§20 rule 2). TENANT-SCOPED.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_center_inboxes', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();

            $table->foreignId('help_center_space_id')
                ->constrained('help_center_spaces')
                ->cascadeOnDelete();

            $table->string('name', 100);

            /*
             * The generated inbound identifier (§8) — the `a8f4k2m9` of
             * `inbox-a8f4k2m9@inbound.projectblock.app`.
             *
             * The TOKEN only, never the composed address (HC-D5): the domain comes from config,
             * so moving domains is an env change rather than a rewrite of every row.
             *
             * UNIQUE ACROSS THE WHOLE TABLE, not per tenant. §20 rule 5 — "one inbound address
             * belongs to exactly one Inbox" — is what makes routing possible at all: mail
             * arrives carrying nothing but this token, before any workspace is known, so a
             * token shared by two tenants would be a message with two homes.
             */
            $table->string('inbound_id', 32)->unique();

            /*
             * When the setup wizard was finished for this Inbox (HC-D3).
             *
             * Null means onboarding has never been completed. The workspace's own onboarding is
             * complete once ANY inbox carries this, which is what stops the second Space from
             * restarting the wizard (§20 rule 12).
             */
            $table->timestamp('setup_completed_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->integer('position')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            // "Must be unique within the Space" (§5) — two Spaces may each have a "General
            // Support", which is the point of Spaces.
            $table->unique(['help_center_space_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('help_center_inboxes');
    }
};
