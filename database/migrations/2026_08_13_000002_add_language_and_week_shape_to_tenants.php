<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Language & Time for the workspace (Account §2 — the Preference tab).
 *
 * The table is `tenants`, not `workspaces`: decision D5 makes a Workspace the stancl tenant,
 * so `App\Models\Workspace` extends BaseTenant and reads that table. `timezone` already lives
 * here beside these, which is the clearest confirmation this is the right place.
 *
 * On the WORKSPACE, not the user. The first day of the week and the weekend are facts about
 * how an organisation works — they decide where a calendar breaks and which days a cycle
 * counts — so two people in the same workspace disagreeing about them would put the same cycle
 * on two different dates. `timezone` is deliberately untouched: Settings → General goes on
 * editing that same column, so the two screens are two doors into one value rather than a copy.
 *
 * `weekend_days` is a JSON array of ISO-8601 day numbers (1 = Monday … 7 = Sunday). ISO so it
 * never needs translating, and an array because a working week is not always five days.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            if (! Schema::hasColumn('tenants', 'language')) {
                $table->string('language', 12)->default('en')->after('timezone');
            }
            if (! Schema::hasColumn('tenants', 'first_day_of_week')) {
                // 7 = Sunday, matching the screen's default.
                $table->unsignedTinyInteger('first_day_of_week')->default(7)->after('language');
            }
            if (! Schema::hasColumn('tenants', 'weekend_days')) {
                $table->json('weekend_days')->nullable()->after('first_day_of_week');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            foreach (['language', 'first_day_of_week', 'weekend_days'] as $column) {
                if (Schema::hasColumn('tenants', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
