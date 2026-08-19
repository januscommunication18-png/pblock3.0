<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Help Center Spaces (docs/features/help-center.md §3, §4).
 *
 * A Space is the top-level organizational container inside the Help Center, and a workspace may
 * hold many of them — Customer Support, Billing, Partner Support. TENANT-SCOPED (CLAUDE.md §7).
 *
 * Named `help_center_*` rather than `help_desk_*` on purpose: the twelve `help_desk_*` tables of
 * the archived module still exist with all their rows (see legacy/help-desk/README.md), and this
 * is a new module rather than a second version of that one (HC-D1, HC-D10).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_center_spaces', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();

            $table->string('name', 100);
            $table->text('description')->nullable();

            /*
             * Space Types — MANY per Space, free text (HC-D9).
             *
             * A Space is "Customer Support" and "VIP Support" at the same time, and the values
             * are whatever the workspace calls its own support: there is no fixed vocabulary to
             * constrain them to. JSON rather than a `help_center_space_types` table and a pivot,
             * because nothing joins to a type, nothing orders by one, and no screen lists types
             * independently of the Space that owns them — two tables to store a handful of
             * strings that are only ever read back with their Space would be structure with no
             * question to answer.
             *
             * The day a type becomes a reporting dimension in its own right — counts per type
             * across Spaces — is the day it earns its own table, and that is a migration, not a
             * redesign.
             */
            $table->json('types');

            /*
             * The Space Lead (§3), and one of the two things that grant management rights over
             * this Space (§19). RESTRICTED rather than nulled on delete: a Space with no lead is
             * a Space nobody is responsible for, and the requirement makes the field mandatory.
             * Removing the person means handing the Space to somebody else first.
             */
            $table->foreignId('lead_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->integer('position')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            /*
             * "Space name should be unique within the Workspace" (§3).
             *
             * Enforced by the DATABASE as well as by the form request: two administrators
             * submitting the same name at the same moment both pass validation, and only a
             * constraint decides which one wins.
             */
            $table->unique(['tenant_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('help_center_spaces');
    }
};
