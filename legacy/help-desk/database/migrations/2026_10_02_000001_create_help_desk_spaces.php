<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Help Desk spaces (Workspace & Inbox Assignment requirements §3, §7, §16).
 *
 * A layer between the Help Desk and its inboxes, so one organization can run several separate
 * support operations — a brand, a business unit, a region — without mixing their conversations:
 *
 *   Workspace (the tenant) → Help Desk → SPACE → Inbox → Conversation
 *
 * Called a SPACE rather than a workspace (decision H37). The requirements call it a "Help Center
 * Workspace", but `Workspace` in this codebase is the stancl tenant — the thing a person switches
 * between in the topbar — and two different things called Workspace on the same screen is a
 * confusion that would outlive the wording.
 *
 * The assignment lives on the INBOX as a nullable `help_desk_space_id`, not in a pivot table:
 * §7's Phase 1 rule is one inbox to one space, and a pivot would model a many-to-many the
 * requirements explicitly rule out — while making "which space owns this inbox?" a join.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_desk_spaces', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('help_desk_id')->constrained('help_desks')->cascadeOnDelete();

            $table->string('name', 80);

            // §5's optional description — what this space is for, in the creator's words.
            $table->string('description', 255)->nullable();

            /*
             * §5's optional categorization: customer_support | partner_support | retail_support
             * | internal_support | business_unit | other.
             *
             * A label, not a behaviour. Nothing branches on it — the moment something does, it
             * stops being a category and becomes a mode, and modes need their own decision.
             */
            $table->string('type', 30)->nullable();

            /*
             * §5's "Icon / Avatar. Optional." — a colour, and the name's initial rendered on it.
             *
             * Not an upload: a file pipeline, a storage disk and a moderation question, all for
             * decoration on a row in a list. A colour gives the same thing a person actually
             * needs from an avatar here, which is telling three spaces apart at a glance.
             */
            $table->string('color', 9)->nullable();

            /*
             * §8's Archive. Archived rather than deleted, because a space owns inboxes that own
             * conversations: deletion would ask what happens to them, which is the question
             * H21 already declined to answer for inboxes.
             */
            $table->timestamp('archived_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            // Two spaces called "Partner Support" is an ambiguity nobody can resolve from a
            // switcher — the same rule inboxes already follow.
            $table->unique(['help_desk_id', 'name']);
        });

        Schema::table('help_desk_inboxes', function (Blueprint $table) {
            /*
             * Which space owns this inbox (§7).
             *
             * NULLABLE, deliberately: §12 is about an inbox that exists and has not been
             * assigned yet, so "unassigned" has to be a state the schema can hold rather than
             * one the UI pretends does not happen.
             *
             * `nullOnDelete` is the safety net under archiving: if a space is ever deleted
             * outright, its inboxes become unassigned rather than disappearing with it.
             */
            $table->foreignId('help_desk_space_id')->nullable()->after('help_desk_id')
                ->constrained('help_desk_spaces')->nullOnDelete();
        });

        /*
         * Existing inboxes are given a space rather than left in limbo.
         *
         * A Help Desk that already has inboxes has been running without this layer, and the
         * honest reading of that is "one support operation" — so it gets one space, named for
         * what the requirements call the default case, holding everything it already had. The
         * alternative is every existing inbox showing as unassigned on the first screen after
         * deploy, which reads as data loss.
         */
        $name = (string) config('help-desk.default_space', 'Customer Support');
        $now = now();

        DB::table('help_desks')->orderBy('id')->each(function ($helpDesk) use ($name, $now) {
            $inboxes = DB::table('help_desk_inboxes')->where('help_desk_id', $helpDesk->id)->count();

            if ($inboxes === 0) {
                return;
            }

            $spaceId = DB::table('help_desk_spaces')->insertGetId([
                'tenant_id' => $helpDesk->tenant_id,
                'help_desk_id' => $helpDesk->id,
                'name' => $name,
                'description' => 'Everything this Help Desk was handling before spaces existed.',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('help_desk_inboxes')
                ->where('help_desk_id', $helpDesk->id)
                ->update(['help_desk_space_id' => $spaceId]);
        });
    }

    public function down(): void
    {
        Schema::table('help_desk_inboxes', function (Blueprint $table) {
            $table->dropForeign(['help_desk_space_id']);
            $table->dropColumn('help_desk_space_id');
        });

        Schema::dropIfExists('help_desk_spaces');
    }
};
