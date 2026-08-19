<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Help Desk invites, activity and setup state
 * (docs/features/help-desk.md — Phase 1, slices 3 and 4: FR-1.5, FR-1.3, FR-1.9).
 *
 * `help_desk_invites` is NOT a second invitation pipeline (decision H11). A Help Desk invite
 * rides on the workspace invitation that already exists — one token, one email, one acceptance
 * screen — and this row records what should happen to the person IN THE HELP DESK once they
 * accept. That is exactly what the third acceptance criterion asks for: "a new coworker
 * invitation results in Workspace membership plus Help Desk membership after acceptance".
 *
 * `help_desk_activity` follows the per-domain activity pattern already in the codebase
 * (`project_activity`, `epic_activity`) rather than a generic `audit_events` table (H3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_desk_invites', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('help_desk_id')->constrained('help_desks')->cascadeOnDelete();

            /*
             * The workspace invitation this rides on. UNIQUE, and cascading: revoking or
             * deleting the invitation takes the Help Desk intent with it, so a stale row can
             * never grant Help Desk membership to somebody whose invitation was withdrawn.
             */
            $table->foreignId('workspace_invitation_id')->unique()
                ->constrained('workspace_invitations')->cascadeOnDelete();

            // Denormalized from the invitation so the pending list reads without a join, and so
            // the record still says who was invited after the invitation row is gone.
            $table->string('email');

            $table->string('role', 20);

            /*
             * Which inboxes they should get (FR-1.7), as JSON rather than a pivot.
             *
             * This is INTENT, not access: nothing can be granted until there is a member row to
             * grant it to. A pivot would need rows pointing at a membership that does not exist
             * yet, and would then have to be migrated into the real one on acceptance.
             */
            $table->json('inbox_ids')->nullable();

            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('redeemed_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['help_desk_id', 'redeemed_at']);
        });

        /*
         * The Help Desk activity stream (FR-1.9).
         *
         * Append-only by intent, like the other activity tables: entries are written by
         * HelpDeskActivityRecorder and never updated. Labels are resolved and STORED at write
         * time — an entry that read its subject's current name would rewrite history every time
         * somebody was renamed, and history that changes is not history.
         */
        Schema::create('help_desk_activity', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('help_desk_id')->constrained('help_desks')->cascadeOnDelete();

            // Null for anything the system did on nobody's behalf.
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('event', 40);

            // What the event was about, as it read at the time: a person's name, an inbox name.
            $table->string('subject', 190)->nullable();

            $table->json('meta')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            // The feed is read newest-first for one Help Desk, and paginated (§13).
            $table->index(['help_desk_id', 'id']);
        });

        Schema::table('help_desks', function (Blueprint $table) {
            /*
             * When the setup wizard was finished (FR-1.3).
             *
             * Null means "never set up", which is what puts the wizard in front of an
             * administrator instead of an empty overview. A nullable timestamp rather than a
             * boolean: "when" answers "whether" as well, and it is one of the few facts about
             * a workspace's Help Desk worth being able to date.
             */
            $table->timestamp('setup_completed_at')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('help_desks', function (Blueprint $table) {
            $table->dropColumn('setup_completed_at');
        });

        Schema::dropIfExists('help_desk_activity');
        Schema::dropIfExists('help_desk_invites');
    }
};
