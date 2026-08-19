<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 of the Help Center's onboarding (docs/features/help-center.md, P2 §2).
 *
 * The six-step wizard needs four things Phase 1 had no reason to store: who is in a Space's
 * support group, the workflow its conversations will move through, how the Space behaves, and —
 * because P2 §2 forbids writing any of that before Step 6 is confirmed — somewhere to keep the
 * half-finished form in the meantime (HC-D11).
 *
 * All tenant-scoped (CLAUDE.md §7).
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Department Groups (P2 §6) — free text, many per Space, exactly like `types` and for
         * the same reasons (HC-D13). Optional: a Space with one team has no groups to name.
         */
        Schema::table('help_center_spaces', function (Blueprint $table) {
            $table->json('department_groups')->nullable()->after('types');
        });

        // The support group (P2 §7, §8).
        Schema::create('help_center_space_members', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->foreignId('help_center_space_id')->constrained('help_center_spaces')->cascadeOnDelete();

            /*
             * NULL until an invited person accepts.
             *
             * P2 §8 allows adding somebody by email who is not yet a workspace member, so a row
             * can exist before the user does. `email` is what identifies them until then, which
             * is why the unique key is on the email and not on the user id.
             */
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('workspace_invitation_id')->nullable()
                ->constrained('workspace_invitations')->nullOnDelete();

            $table->string('email');

            // Which of the Space's own groups this person belongs to — several, or none.
            $table->json('department_groups')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['help_center_space_id', 'email']);
        });

        // The workflow (P2 §11–§16).
        Schema::create('help_center_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->foreignId('help_center_space_id')->constrained('help_center_spaces')->cascadeOnDelete();

            $table->string('name', 60);
            $table->string('color', 7);
            $table->string('responsibility', 10)->default('assignee');
            $table->boolean('is_active')->default(true);

            /*
             * `open` / `closed` for the two protected rows, null for everything else (HC-D14).
             *
             * The marker rather than the position or the name: a user can type "Open" as a
             * custom status name and a row can be dragged anywhere, but neither creates or
             * destroys a SYSTEM status. Everything protected keys off this column.
             */
            $table->string('system_key', 10)->nullable();

            $table->integer('position');

            // User ids, multi-select (P2 §14). JSON for the same reason as department_groups:
            // nothing joins to it, and it is only ever read back with its status.
            $table->json('default_assignees')->nullable();

            $table->timestamps();

            $table->unique(['help_center_space_id', 'name']);
            $table->index(['help_center_space_id', 'position']);
        });

        // How a Space behaves (P2 §17–§24). One row per Space, kept off the Space (HC-D15).
        Schema::create('help_center_space_settings', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->foreignId('help_center_space_id')->unique()
                ->constrained('help_center_spaces')->cascadeOnDelete();

            // The seven toggles of P2 §18, keyed by name, so an eighth is a config entry.
            $table->json('metadata')->nullable();

            $table->boolean('auto_bcc_enabled')->default(false);
            $table->string('auto_bcc_email')->nullable();

            $table->boolean('reassign_enabled')->default(false);
            // TOTAL minutes (HC-D16). The UI splits it into Hours + Minutes; the data is a
            // duration, and one integer cannot hold "2 hours 90 minutes".
            $table->integer('reassign_after_minutes')->nullable();
            $table->string('reassign_destination', 20)->default('unassigned');

            $table->boolean('auto_follow_mentions')->default(true);

            $table->timestamps();
        });

        /*
         * The wizard's own state (HC-D11).
         *
         * A ROW rather than localStorage: P2 §2 asks for the draft to survive the user leaving,
         * and a workspace's configuration should be somewhere the server can validate it.
         *
         * Keyed per USER as well as per tenant — two administrators setting up at the same time
         * are filling in two different forms, and one shared row would have them overwrite each
         * other mid-sentence.
         */
        Schema::create('help_center_setup_drafts', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // The furthest step reached, so returning resumes rather than restarts.
            $table->integer('step')->default(1);
            $table->json('payload')->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('help_center_setup_drafts');
        Schema::dropIfExists('help_center_space_settings');
        Schema::dropIfExists('help_center_statuses');
        Schema::dropIfExists('help_center_space_members');

        Schema::table('help_center_spaces', function (Blueprint $table) {
            $table->dropColumn('department_groups');
        });
    }
};
