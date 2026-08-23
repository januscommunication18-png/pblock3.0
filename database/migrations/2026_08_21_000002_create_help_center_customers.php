<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The person on the other end of a Request (docs/features/help-center.md, P33). TENANT-SCOPED.
 *
 * Until now a customer was three columns on the Request — `customer_email`, `customer_name` and
 * nothing else. That is enough to show a row and not enough to answer the questions the ticket
 * panel is asked: how many tickets has this person opened, when did they first write, what
 * company are they at, what is their number.
 *
 * Scoped to the WORKSPACE, not to a Space. Somebody who writes to Billing on Monday and to
 * Support on Tuesday is one person with two tickets, and a per-Space record would say they are
 * two people with one each — which is exactly the count the panel is meant to make useful.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_center_customers', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();

            /*
             * The email IS the identity.
             *
             * It is the only thing an inbound message reliably carries about its sender — names
             * are absent, spelled three ways, or the mail client's idea of one — and it is what
             * a reply is addressed to. Unique per workspace, so matching a sender is a lookup
             * rather than a guess.
             */
            $table->string('email');

            $table->string('name')->nullable();
            $table->string('company')->nullable();
            $table->string('phone', 40)->nullable();

            /*
             * Their id in whatever system the workspace considers authoritative — a CRM, a
             * billing account. Nullable and untouched by this module: we do not invent it, we
             * display it when somebody fills it in.
             */
            $table->string('external_id', 100)->nullable();

            // When they first wrote in. Stored rather than derived, so it survives a Request
            // being deleted and does not need a MIN() over every ticket to render a panel.
            $table->timestamp('first_contact_at')->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'email']);
        });

        Schema::table('help_center_requests', function (Blueprint $table) {
            /*
             * NULLABLE, and null-on-delete.
             *
             * `customer_email` and `customer_name` stay on the Request exactly as they were:
             * they are what THAT email said, and a Request must still render if its customer
             * record is ever removed. This column is the link to the richer record, not a
             * replacement for the facts the message carried.
             */
            $table->foreignId('help_center_customer_id')->nullable()->after('customer_name')
                ->constrained('help_center_customers')->nullOnDelete();
        });

        /*
         * Backfill: every Request already stored gets a customer.
         *
         * Without this the panel would show "1 ticket, first contact today" for somebody with a
         * year of history — which is worse than showing nothing, because it looks like an
         * answer. Done in PHP rather than one INSERT…SELECT so the same normalisation the model
         * applies (lower-cased, trimmed) is the one that lands here.
         */
        $rows = DB::table('help_center_requests')
            ->whereNotNull('customer_email')
            ->orderBy('id')
            ->get(['id', 'tenant_id', 'customer_email', 'customer_name', 'created_at']);

        $seen = [];

        foreach ($rows as $row) {
            $email = mb_strtolower(trim((string) $row->customer_email));

            if ($email === '') {
                continue;
            }

            $key = $row->tenant_id.'|'.$email;

            if (! isset($seen[$key])) {
                $existing = DB::table('help_center_customers')
                    ->where('tenant_id', $row->tenant_id)->where('email', $email)->first();

                $seen[$key] = $existing?->id ?? DB::table('help_center_customers')->insertGetId([
                    'tenant_id' => $row->tenant_id,
                    'email' => $email,
                    'name' => $row->customer_name,
                    // The oldest Request from this address, because the rows are ordered by id.
                    'first_contact_at' => $row->created_at,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('help_center_requests')
                ->where('id', $row->id)
                ->update(['help_center_customer_id' => $seen[$key]]);
        }
    }

    public function down(): void
    {
        Schema::table('help_center_requests', function (Blueprint $table) {
            $table->dropForeign(['help_center_customer_id']);
            $table->dropColumn('help_center_customer_id');
        });

        Schema::dropIfExists('help_center_customers');
    }
};
