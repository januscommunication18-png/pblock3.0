<?php

use App\Models\HelpDeskEmailAddress;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Connected customer email addresses (Inbound Email requirements §5, §7, §10).
 *
 * This is the shape change the requirements make to Phase 2: an inbox's `inbound_address` stops
 * being something an administrator types and becomes something the system GENERATES and nobody
 * can edit (§2). What an administrator connects instead is their own customer-facing address —
 * support@acme.com — which they configure their mail provider to FORWARD to the generated one.
 *
 * So the two are different facts and now live in different places:
 *
 *   help_desk_inboxes.inbound_address   generated, read-only, one per inbox, unique everywhere.
 *                                       The forwarding DESTINATION.
 *   help_desk_email_addresses.address   what customers actually write to, one row per connected
 *                                       address, with its own connection status. The forwarding
 *                                       SOURCE.
 *
 * Phase 2 decided against a table here (decision H16) on the grounds that an inbox has one
 * address and a table would model a many-to-one that did not exist. It exists now: §10 lists
 * several customer addresses per inbox, each with its own status and its own last-email time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_desk_email_addresses', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('help_desk_id')->constrained('help_desks')->cascadeOnDelete();
            $table->foreignId('help_desk_inbox_id')->constrained('help_desk_inboxes')->cascadeOnDelete();

            // The customer-facing address — the one people actually write to.
            $table->string('address', 190);

            /*
             * setup_required | waiting_for_email | connected | error | disabled (§7).
             *
             * Starts at `setup_required` and becomes `connected` the first time a message
             * arrives at the inbox carrying this address — which is the only honest proof that
             * somebody's forwarding rule works. Nothing this application can do from its own
             * side would prove it: the forwarding lives in the customer's mail provider.
             */
            $table->string('status', 20)->default(HelpDeskEmailAddress::STATUS_SETUP_REQUIRED);

            // When verification was last asked for (§8), so a wait that never resolves can be
            // called an error on read rather than by a scheduled sweep.
            $table->timestamp('verification_started_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_email_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            /*
             * One connection per address per Help Desk — an index rather than a unique
             * constraint, for the same reason `help_desk_members` is: a soft-deleted row would
             * otherwise block reconnecting an address somebody removed, with an error no screen
             * can explain.
             *
             * NOT unique across tenants, unlike the generated inbound address: two workspaces
             * can legitimately both connect `info@theiragency.example`, because this column
             * routes nothing. The generated address is what routes.
             */
            $table->index(['help_desk_id', 'address'], 'hd_email_addresses_address_idx');
            $table->index(['help_desk_inbox_id', 'status'], 'hd_email_addresses_status_idx');
        });

        /*
         * Existing inboxes carry a TYPED address from Phase 2, which the requirements now say
         * must be generated and read-only. That typed value is the customer-facing address —
         * exactly what this new table is for — so it is moved rather than discarded, and the
         * inbox is given a generated address to replace it.
         *
         * Marked `connected` on the way across: those inboxes have been receiving at that
         * address, and demoting a working connection to "setup required" during a migration
         * would be this change telling a lie about the state of the system.
         */
        $domain = (string) config('help-desk.inbound.domain', 'inbound.projectblock.app');
        $now = now();

        DB::table('help_desk_inboxes')->orderBy('id')->each(function ($inbox) use ($domain, $now) {
            if (! empty($inbox->inbound_address)) {
                DB::table('help_desk_email_addresses')->insert([
                    'tenant_id' => $inbox->tenant_id,
                    'help_desk_id' => $inbox->help_desk_id,
                    'help_desk_inbox_id' => $inbox->id,
                    'address' => $inbox->inbound_address,
                    'status' => HelpDeskEmailAddress::STATUS_CONNECTED,
                    'verified_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            DB::table('help_desk_inboxes')->where('id', $inbox->id)->update([
                'inbound_address' => Str::slug((string) $inbox->name).'-'.Str::lower(Str::random(5)).'@'.$domain,
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('help_desk_email_addresses');
    }
};
