<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Give each project its own workspace-defined status (project_states). Nullable so existing
 * projects keep working; cleared to null if the referenced state is deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            if (! Schema::hasColumn('projects', 'state_id')) {
                $table->foreignId('state_id')->nullable()->after('status')
                    ->constrained('project_states')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            if (Schema::hasColumn('projects', 'state_id')) {
                $table->dropConstrainedForeignId('state_id');
            }
        });
    }
};
