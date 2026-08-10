<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks the first-workspace invite step as done (spec §9): set when the user either
 * sends invitations or chooses "I'll do it later". Lets login resume the invite step
 * if the user created a workspace but abandoned onboarding before finishing invites.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('onboarding_profiles', function (Blueprint $table) {
            if (! Schema::hasColumn('onboarding_profiles', 'workspace_setup_completed_at')) {
                $table->timestamp('workspace_setup_completed_at')->nullable()->after('completed_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('onboarding_profiles', function (Blueprint $table) {
            if (Schema::hasColumn('onboarding_profiles', 'workspace_setup_completed_at')) {
                $table->dropColumn('workspace_setup_completed_at');
            }
        });
    }
};
