<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The Setup Inbox flow: the inbound id, and where each space has got to in setting itself up.
 *
 * Two things, one migration, because they arrive together and neither is useful alone — the
 * flow's whole job is to walk a space from "no inbox" to "an inbox with a generated address and
 * somebody to read it".
 *
 * `help_desk_inboxes.inbound_id` — the unique id the address is built from, stored beside it
 * rather than parsed back out. The flow's data requirement is explicit that the id is a fact of
 * its own, held independently of the inbox name, the space name and the customer's address.
 *
 * `help_desk_spaces.setup_step` / `setup_completed_at` / `setup_inbox_id` — "save progress after
 * each completed step" and "resume from the first incomplete step". A stored step rather than
 * one derived from the data, because step 3 (connect the email) cannot be derived: it completes
 * when somebody has read the forwarding instructions, and mail proving the forward works may
 * arrive days later or never. A wizard that reopened at step 3 for ever would be a wizard nobody
 * finishes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('help_desk_inboxes', function (Blueprint $table) {
            /*
             * Nullable and unique. Nullable because rows written before this migration have no
             * id until one is generated for them below; unique for the same reason the address
             * is — it IS the address, minus the fixed prefix and domain.
             */
            $table->string('inbound_id', 32)->nullable()->unique()->after('inbound_address');
        });

        Schema::table('help_desk_spaces', function (Blueprint $table) {
            // 1..4 while the wizard is running. Never 0: a space that has not started is at
            // step 1, which is where "Continue to Setup Inbox" opens.
            $table->unsignedTinyInteger('setup_step')->default(1)->after('color');

            // Null means unfinished, and that is what puts "Continue to Setup Inbox" on the row.
            $table->timestamp('setup_completed_at')->nullable()->after('setup_step');

            /*
             * Which inbox the flow is configuring.
             *
             * Recorded rather than inferred from "the space's only inbox": a space can hold
             * several, and resuming at step 2 has to add addresses to the one step 1 named, not
             * to whichever comes back first from a query.
             */
            $table->foreignId('setup_inbox_id')->nullable()->after('setup_completed_at')
                ->constrained('help_desk_inboxes')->nullOnDelete();
        });

        Schema::table('help_desk_email_addresses', function (Blueprint $table) {
            /*
             * What to call this address on screen (step 2's Name column).
             *
             * Nullable, and the screen falls back to the address itself: a label is how a team
             * refers to a mailbox out loud — "Billing" — and an address with none is not
             * misconfigured, it is just one nobody felt the need to rename.
             */
            $table->string('label', 80)->nullable()->after('address');
        });

        $this->giveExistingInboxesAnId();
        $this->markSetUpSpacesComplete();
    }

    /**
     * Every existing inbox gets an id, and most get a new address with it.
     *
     * The addresses written before this migration were `{inbox-name}-{suffix}@…`, which the
     * Setup flow forbids: the name must not appear in the address, because renaming an inbox
     * must not change where its mail arrives.
     *
     * But an address that is already receiving mail is one somebody has a forwarding rule
     * pointing at, and rewriting it would break that rule silently, in a system this
     * application cannot see — the exact failure the read-only rule exists to prevent. So the
     * rule here is: reformat an inbox that has never received anything, and leave one that has
     * alone with its id extracted from the address it already has. The old address stays valid
     * for ever; it simply is not the shape new ones take.
     */
    private function giveExistingInboxesAnId(): void
    {
        $domain = (string) config('help-desk.inbound.domain', 'inbound.projectblock.app');

        DB::table('help_desk_inboxes')->orderBy('id')->each(function ($inbox) use ($domain) {
            $receiving = DB::table('help_desk_conversations')->where('help_desk_inbox_id', $inbox->id)->exists()
                || DB::table('help_desk_email_addresses')
                    ->where('help_desk_inbox_id', $inbox->id)
                    ->whereNotNull('last_email_at')
                    ->exists();

            if ($receiving && ! empty($inbox->inbound_address)) {
                // Keep the address; take the id from whatever is in front of the @, so the
                // column is populated for every row either way.
                DB::table('help_desk_inboxes')->where('id', $inbox->id)->update([
                    'inbound_id' => Str::limit(Str::before((string) $inbox->inbound_address, '@'), 32, ''),
                ]);

                return;
            }

            do {
                $id = Str::lower(Str::random(6));
                $address = 'inbox-'.$id.'@'.$domain;
            } while (DB::table('help_desk_inboxes')->where('inbound_address', $address)->exists());

            DB::table('help_desk_inboxes')->where('id', $inbox->id)->update([
                'inbound_id' => $id,
                'inbound_address' => $address,
            ]);
        });
    }

    /**
     * A space whose inbox is already connected to a customer address is not asked to set it up.
     *
     * The test is the ADDRESS, not the inbox: everything before this flow created inboxes
     * directly, and an inbox with a customer address forwarded into it is a finished setup by
     * any reading — showing "Continue to Setup Inbox" on one that has been taking mail for a
     * month would be the screen contradicting itself. An inbox with no address, on the other
     * hand, receives nothing, and the flow is exactly what it needs.
     */
    private function markSetUpSpacesComplete(): void
    {
        $now = now();

        DB::table('help_desk_spaces')->orderBy('id')->each(function ($space) use ($now) {
            $inbox = DB::table('help_desk_inboxes')
                ->where('help_desk_space_id', $space->id)
                ->orderBy('id')
                ->first();

            if (! $inbox) {
                return;
            }

            $connected = DB::table('help_desk_email_addresses')
                ->where('help_desk_inbox_id', $inbox->id)
                ->whereNull('deleted_at')
                ->exists();

            DB::table('help_desk_spaces')->where('id', $space->id)->update([
                // Step 2 either way: the inbox exists and is named, so step 1 is behind them.
                'setup_step' => $connected ? 4 : 2,
                'setup_completed_at' => $connected ? $now : null,
                'setup_inbox_id' => $inbox->id,
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('help_desk_email_addresses', function (Blueprint $table) {
            $table->dropColumn('label');
        });

        Schema::table('help_desk_spaces', function (Blueprint $table) {
            $table->dropForeign(['setup_inbox_id']);
            $table->dropColumn(['setup_step', 'setup_completed_at', 'setup_inbox_id']);
        });

        Schema::table('help_desk_inboxes', function (Blueprint $table) {
            $table->dropUnique(['inbound_id']);
            $table->dropColumn('inbound_id');
        });
    }
};
