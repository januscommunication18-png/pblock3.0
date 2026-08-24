<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Space's working week (docs/features/helpdesk-sla.md, §5–§6). TENANT-SCOPED.
 *
 * The calendar an SLA policy counts its minutes against. Several per Space is deliberate: one
 * support team can run US and EU hours, and §6 makes the timezone part of the calendar rather
 * than of the Space, so two calendars are the only way to say that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_center_business_hours', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->foreignId('help_center_space_id')->constrained('help_center_spaces')->cascadeOnDelete();

            $table->string('name', 80);

            /*
             * IANA, and the SLA clock's ONLY notion of local time (§6).
             *
             * Not the workspace's timezone and not the viewer's: "08:00–18:00" is a promise made
             * in one place, and a promise that moves with whoever is reading it is not a promise.
             */
            $table->string('timezone', 64)->default('UTC');

            /*
             * The week, as `{"mon":{"open":"08:00","close":"18:00"}, "sat":null, …}`.
             *
             * JSON and not seven rows: the whole week is authored, saved and read as one object,
             * and nothing ever points at a single day. A `help_center_business_hour_days` table
             * would be seven rows that only ever move together.
             *
             * `null` for a day is Closed — distinct from an absent key, which the reader also
             * treats as closed, so a malformed blob fails towards NOT promising service.
             */
            $table->json('schedule');

            // The calendar a new policy starts with. One per Space; enforced by the writer,
            // because MySQL has no partial unique index and a unique on (space, is_default)
            // would refuse a Space its second NON-default calendar.
            $table->boolean('is_default')->default(false);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['help_center_space_id', 'name'], 'hc_bh_space_name_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('help_center_business_hours');
    }
};
