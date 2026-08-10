<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Give each project an (optional) priority — from the workspace's project_priorities — plus
 * start/end dates, all editable from the project card. Nullable so existing projects are safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            if (! Schema::hasColumn('projects', 'priority_id')) {
                $table->foreignId('priority_id')->nullable()->after('state_id')
                    ->constrained('project_priorities')->nullOnDelete();
            }
            if (! Schema::hasColumn('projects', 'start_date')) {
                $table->date('start_date')->nullable()->after('priority_id');
            }
            if (! Schema::hasColumn('projects', 'end_date')) {
                $table->date('end_date')->nullable()->after('start_date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            if (Schema::hasColumn('projects', 'priority_id')) {
                $table->dropConstrainedForeignId('priority_id');
            }
            foreach (['start_date', 'end_date'] as $col) {
                if (Schema::hasColumn('projects', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
