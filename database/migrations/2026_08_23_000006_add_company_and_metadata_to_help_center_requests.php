<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Request's link to a Company, and the metadata it arrived with (P75 §8–§9).
 *
 * `help_center_customer_id` is already here (P33). This adds the other half of the pair the
 * requirement asks a Ticket to maintain, plus the raw parsed source values — kept because §8
 * asks for the original inbound metadata to stay available for audit, and because Reprocess
 * (§14) has nothing to re-run the mappings OVER without it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('help_center_requests', function (Blueprint $table) {
            $table->foreignId('help_center_company_id')->nullable()->after('help_center_customer_id')
                ->constrained('help_center_companies')->nullOnDelete();

            /*
             * The parser's output map, as it was at ingest — `sender_email`, `email_domain`,
             * `ticket_subject` and whatever an integration sent in its own bag.
             *
             * NOT the raw email: `help_center_messages` already keeps that (P68 keeps both the
             * stripped and the raw body). This is the small structured thing the mappings read,
             * which is what makes it worth storing twice.
             */
            $table->json('inbound_metadata')->nullable()->after('help_center_company_id');
        });
    }

    public function down(): void
    {
        Schema::table('help_center_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('help_center_company_id');
            $table->dropColumn('inbound_metadata');
        });
    }
};
