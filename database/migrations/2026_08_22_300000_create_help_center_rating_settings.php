<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Space's CSAT configuration (docs/features/help-center.md, P56). TENANT-SCOPED.
 *
 * Its own table rather than more columns on `help_center_space_settings`, which is already a wide
 * row of unrelated switches. This is twenty-odd fields that only mean anything together, and
 * mixing them in would make both harder to read.
 *
 * One row per Space — the requirement's "managed independently for each Space" as a constraint.
 * A missing row means the packaged defaults (see RatingSettings::for()), the same design the
 * email templates use (P48): a Space that has never opened this page still has a coherent answer
 * for every question, and "Rating is off" is one of those answers rather than a null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_center_rating_settings', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->foreignId('help_center_space_id')->unique()
                ->constrained('help_center_spaces')->cascadeOnDelete();

            /*
             * OFF by default.
             *
             * Emailing every customer of every existing Space the moment this ships would be the
             * single most damaging default in this module. Turning it on is a decision.
             */
            $table->boolean('enabled')->default(false);

            // ---- the experience ----
            // stars5 | emoji5 | thumbs | scale10 — see config('help-center.rating_types').
            $table->string('rating_type', 20)->default('stars5');
            // The five display labels. Changing a label must not change the score it sits on
            // (the requirement is explicit), which is why these are labels and the score is the
            // array position — see HelpCenterRating::SCORES.
            $table->json('labels')->nullable();
            $table->boolean('allow_comment')->default(true);
            // optional | always | low_only
            $table->string('comment_requirement', 20)->default('low_only');

            // ---- the trigger ----
            // resolved | closed | status | manual
            $table->string('trigger', 20)->default('resolved');
            // Only when `trigger` is `status`. nullOnDelete because deleting the status somebody
            // built the trigger on must not delete the Space's rating configuration with it.
            $table->foreignId('trigger_status_id')->nullable()
                ->constrained('help_center_statuses')->nullOnDelete();
            // 0 = immediately. Minutes rather than an enum so "custom delay" is the same field.
            $table->unsignedInteger('delay_minutes')->default(0);

            // ---- reminders ----
            $table->boolean('reminder_enabled')->default(false);
            $table->unsignedSmallInteger('reminder_after_days')->default(2);
            $table->unsignedTinyInteger('reminder_max')->default(1);

            // ---- the link ----
            // NULL = never expires.
            $table->unsignedSmallInteger('expires_days')->nullable()->default(14);
            $table->boolean('allow_change')->default(false);
            $table->boolean('rerequest_after_reopen')->default(false);

            // ---- negative feedback ----
            // A score at or below this is "low". 2 on a five-point scale.
            $table->unsignedTinyInteger('low_threshold')->default(2);
            $table->boolean('low_notify_agent')->default(true);
            $table->boolean('low_notify_admin')->default(true);
            $table->boolean('low_internal_note')->default(true);
            $table->boolean('low_reopen')->default(false);
            // The tag to apply. nullOnDelete for the reason the trigger status is.
            $table->foreignId('low_tag_id')->nullable()
                ->constrained('help_center_tags')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('help_center_rating_settings');
    }
};
