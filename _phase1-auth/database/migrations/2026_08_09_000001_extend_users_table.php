<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 auth — extend the default Laravel users table.
 * Adds ProjectBlock profile/consent fields and makes password nullable
 * (passwords live in user_password_credentials; users may be passwordless — see spec D-A2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Laravel's default table has: name, email, email_verified_at, password.
            if (! Schema::hasColumn('users', 'full_name')) {
                $table->string('full_name')->nullable()->after('id');
            }
            if (! Schema::hasColumn('users', 'avatar_url')) {
                $table->string('avatar_url')->nullable()->after('email_verified_at');
            }
            if (! Schema::hasColumn('users', 'status')) {
                $table->string('status', 20)->default('active')->after('avatar_url');
            }
            if (! Schema::hasColumn('users', 'locale')) {
                $table->string('locale', 12)->nullable()->after('status');
            }
            if (! Schema::hasColumn('users', 'timezone')) {
                $table->string('timezone', 64)->nullable()->after('locale');
            }
            if (! Schema::hasColumn('users', 'marketing_opt_in')) {
                $table->boolean('marketing_opt_in')->default(false)->after('timezone');
            }
            if (! Schema::hasColumn('users', 'terms_accepted_at')) {
                $table->timestamp('terms_accepted_at')->nullable()->after('marketing_opt_in');
            }
        });

        // Password is optional in Phase 1 (passwordless-first). Laravel 11+/13 supports change() without doctrine/dbal.
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['full_name', 'avatar_url', 'status', 'locale', 'timezone', 'marketing_opt_in', 'terms_accepted_at'] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
