<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'full_name'))         $table->string('full_name')->nullable()->after('id');
            if (! Schema::hasColumn('users', 'avatar_url'))        $table->string('avatar_url')->nullable()->after('email_verified_at');
            if (! Schema::hasColumn('users', 'status'))            $table->string('status', 20)->default('active')->after('avatar_url');
            if (! Schema::hasColumn('users', 'locale'))            $table->string('locale', 12)->nullable()->after('status');
            if (! Schema::hasColumn('users', 'timezone'))          $table->string('timezone', 64)->nullable()->after('locale');
            if (! Schema::hasColumn('users', 'marketing_opt_in'))  $table->boolean('marketing_opt_in')->default(false)->after('timezone');
            if (! Schema::hasColumn('users', 'terms_accepted_at')) $table->timestamp('terms_accepted_at')->nullable()->after('marketing_opt_in');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
            $table->string('name')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['full_name','avatar_url','status','locale','timezone','marketing_opt_in','terms_accepted_at'] as $col) {
                if (Schema::hasColumn('users', $col)) $table->dropColumn($col);
            }
        });
    }
};
