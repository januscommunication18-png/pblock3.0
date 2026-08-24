<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Days the SLA clock does not run (docs/features/helpdesk-sla.md, §7). TENANT-SCOPED.
 *
 * Space-level rather than per calendar (SLA-D4): §7 hangs the Holiday Calendar off the Space and
 * never scopes it to a schedule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_center_sla_holidays', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->foreignId('help_center_space_id')->constrained('help_center_spaces')->cascadeOnDelete();

            $table->string('name', 120);

            /*
             * A real date, even for a repeating holiday.
             *
             * `repeats_annually` reads only the month and day off it, but storing a whole date
             * means a one-off holiday and a recurring one are the same shape, and the year the
             * rule was authored in stays visible.
             */
            $table->date('date');
            $table->boolean('repeats_annually')->default(false);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['help_center_space_id', 'date'], 'hc_holiday_space_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('help_center_sla_holidays');
    }
};
