<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Do not create duplicate customers for the same email or configured unique identifier"
 * (docs/features/help-center.md, P75 §6) — enforced by the DATABASE.
 *
 * `CustomerMatcher` has always checked before inserting, and a check before an insert is not a
 * uniqueness guarantee: two workers ingesting two messages from one new sender both find nothing
 * and both create. Postmark delivers bursts, the queue runs more than one worker, and this is a
 * race that happens in practice rather than in theory.
 *
 * Adding the index means first dealing with any duplicates already stored, which is what the
 * fold below does — keeping the OLDEST row of each address, because it is the one every existing
 * Request was matched onto first.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->fold();

        Schema::table('help_center_customers', function (Blueprint $table) {
            $table->unique(['tenant_id', 'email'], 'hc_customers_tenant_email_unique');
            $table->unique(['tenant_id', 'external_id'], 'hc_customers_tenant_external_unique');
        });
    }

    public function down(): void
    {
        Schema::table('help_center_customers', function (Blueprint $table) {
            $table->dropUnique('hc_customers_tenant_email_unique');
            $table->dropUnique('hc_customers_tenant_external_unique');
        });
    }

    /**
     * Point every Request at the oldest customer for its address, then delete the rest.
     *
     * Deliberately NOT merging the losing rows' `name`, `company` or `phone` into the keeper: a
     * duplicate exists because two ingests raced, so the rows hold the same information from the
     * same source, and picking between them would be inventing a rule for a case that does not
     * arise. The links are what matter, and those are re-pointed.
     */
    private function fold(): void
    {
        $groups = DB::table('help_center_customers')
            ->select('tenant_id', 'email', DB::raw('MIN(id) as keeper'), DB::raw('COUNT(*) as total'))
            ->whereNotNull('email')
            ->groupBy('tenant_id', 'email')
            ->having('total', '>', 1)
            ->get();

        foreach ($groups as $group) {
            $losers = DB::table('help_center_customers')
                ->where('tenant_id', $group->tenant_id)
                ->where('email', $group->email)
                ->where('id', '!=', $group->keeper)
                ->pluck('id');

            if ($losers->isEmpty()) {
                continue;
            }

            DB::table('help_center_requests')
                ->whereIn('help_center_customer_id', $losers)
                ->update(['help_center_customer_id' => $group->keeper]);

            DB::table('help_center_customers')->whereIn('id', $losers)->delete();
        }

        /*
         * A blank external id is not an identity, and MySQL treats two empty strings as equal —
         * so any row holding `''` would collide with every other one under the new index. NULL
         * is what "not provided" has always meant everywhere else in this module.
         */
        DB::table('help_center_customers')->where('external_id', '')->update(['external_id' => null]);
    }
};
