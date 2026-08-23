<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Linking a customer to a Company row (docs/features/help-center.md, P75 §7).
 *
 * The existing free-text `company` column is KEPT, not replaced. It holds what somebody typed
 * into the panel before Companies were rows, and it is what the requirement's mapping table
 * still offers as "Customer → Company (text)". Dropping it would throw away information to gain
 * a column name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('help_center_customers', function (Blueprint $table) {
            $table->foreignId('help_center_company_id')->nullable()->after('company')
                ->constrained('help_center_companies')->nullOnDelete();

            $table->json('tags')->nullable()->after('external_id');

            /*
             * Denormalised, and deliberately so.
             *
             * The Customers list shows Last Ticket for every row, and deriving it means a
             * correlated MAX over `help_center_requests` per customer. `toPanel()` may keep
             * computing it live for one record; a grid of two hundred may not.
             */
            $table->timestamp('last_activity_at')->nullable()->after('first_contact_at');
        });
    }

    public function down(): void
    {
        Schema::table('help_center_customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('help_center_company_id');
            $table->dropColumn(['tags', 'last_activity_at']);
        });
    }
};
