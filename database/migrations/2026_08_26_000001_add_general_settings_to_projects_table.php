<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Project Settings → General: work item visibility and the default assignee
 * (General spec §10/§12).
 *
 * `work_item_view` decides whether ordinary members see every work item in the project or
 * only the ones assigned to them. It defaults to `all`, which is how every existing project
 * behaves today — a migration must not quietly narrow anyone's access.
 *
 * `default_assignee_id` is applied when a work item is created with no assignee chosen (§12).
 * It nulls on delete: losing the member should clear the default, not orphan a reference.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('work_item_view', 20)->default('all')->after('visibility');
            $table->foreignId('default_assignee_id')->nullable()->after('lead_user_id')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_assignee_id');
            $table->dropColumn('work_item_view');
        });
    }
};
