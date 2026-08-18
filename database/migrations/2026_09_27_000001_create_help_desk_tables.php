<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Help Desk membership (docs/features/help-desk.md — Phase 1, slice 2).
 *
 * Four tables in one migration because they are one thing: a Help Desk, the inboxes inside it,
 * the people who work there, and which inboxes each of those people may open. Splitting them
 * across four files would order four migrations that can only ever run together.
 *
 * ALL TENANT-SCOPED (CLAUDE.md §7) except the member/inbox pivot, whose two sides are already
 * scoped — a row there can only join an inbox and a member from the same workspace, and a third
 * copy of the tenant is a third place it can disagree with the other two.
 *
 * There is no `help_desk_roles` table (decision H2) and no generic `audit_events` table
 * (decision H3); both are recorded in the feature doc with the reasoning.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * One Help Desk per workspace — enforced by the unique tenant_id rather than left to
         * whichever code path happens to create it. Everything Help Desk-wide hangs off this
         * row, so a second one would silently split a workspace's support operation in two.
         */
        Schema::create('help_desks', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->unique();
            $table->string('name');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });

        /*
         * Inboxes. Deliberately minimal in Phase 1: a name is all that inbox-level access needs
         * (FR-1.7). Phase 2 adds the email address, channel and routing rules on top of this row
         * rather than beside it.
         */
        Schema::create('help_desk_inboxes', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('help_desk_id')->constrained('help_desks')->cascadeOnDelete();
            $table->string('name');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            // Two inboxes called "Support" is an ambiguity nobody can resolve from a UI.
            $table->unique(['help_desk_id', 'name']);
        });

        /*
         * Help Desk membership — independent of workspace membership (FR-1.4). Being a
         * workspace admin puts no row in here; only being added to the Help Desk does.
         *
         * SOFT DELETED, because the fifth acceptance criterion requires that removing a member
         * preserves their historical replies, notes and assignments. Those records point at the
         * membership, so the row has to survive its own removal.
         */
        Schema::create('help_desk_members', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('help_desk_id')->constrained('help_desks')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // One of config('help-desk.roles'). A string rather than an enum column: the role
            // list is a product decision (decision H2), and an enum would make adding one a
            // schema migration on a large table.
            $table->string('role', 20);

            // active | inactive. Deactivation (FR-1.8) is not removal — an inactive member keeps
            // their row, their inbox access and their history, and simply cannot get in.
            $table->string('status', 20)->default('active');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            /*
             * One membership per person per Help Desk.
             *
             * NOT a unique index: a soft-deleted row still occupies it, so re-adding somebody
             * who was once removed would fail at the database with an error no screen can
             * explain. The manager service restores the existing row instead — which is also
             * what keeps their history attached (see HelpDeskMemberManager::add).
             */
            $table->index(['help_desk_id', 'user_id']);

            // The member list, and the "is this person allowed in?" check on every request.
            $table->index(['tenant_id', 'status']);
            $table->index('user_id');
        });

        /*
         * Which inboxes a member may open (FR-1.7).
         *
         * Rows exist only for roles that are confined to named inboxes. An Admin or Manager
         * works across the whole Help Desk (config `all_inboxes`), so listing every inbox for
         * them would be a list to maintain and a way for access to be wrong after the next
         * inbox is created.
         */
        Schema::create('help_desk_member_inboxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('help_desk_member_id')->constrained('help_desk_members')->cascadeOnDelete();
            $table->foreignId('help_desk_inbox_id')->constrained('help_desk_inboxes')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['help_desk_member_id', 'help_desk_inbox_id'], 'help_desk_member_inbox_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('help_desk_member_inboxes');
        Schema::dropIfExists('help_desk_members');
        Schema::dropIfExists('help_desk_inboxes');
        Schema::dropIfExists('help_desks');
    }
};
