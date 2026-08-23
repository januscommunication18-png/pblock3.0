<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Space's Company custom fields (docs/features/help-center.md, P18). TENANT-SCOPED.
 *
 * What a workspace wants to record about a customer's company is not something this product can
 * enumerate — Industry, Account Tier, Contract Renewal Time are one team's questions — so the
 * FIELDS are data and only their TYPES are code.
 *
 * Scoped to the Space, like its tags and its statuses: one team's idea of "Company Size" is not
 * another's, and a workspace-wide list would put every Space's questions on every Company form.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_center_company_fields', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->foreignId('help_center_space_id')->constrained('help_center_spaces')->cascadeOnDelete();

            $table->string('name', 60);

            // One of `help-center.company_field_types`. A string rather than an enum column:
            // adding a ninth type should be a config entry, not a migration.
            $table->string('type', 20);

            $table->boolean('is_required')->default(false);

            /*
             * Disable, not delete (P18).
             *
             * A field somebody stopped asking is not a field that never existed — the values
             * already recorded against it stay meaningful — so the row has an off switch that
             * takes it off the form without taking it out of the history.
             */
            $table->boolean('is_active')->default(true);

            /*
             * The choices, for the four types that have them; null for the four that do not.
             *
             * JSON rather than a table: they are read and written whole with their field, are
             * capped, and nothing joins to them. A stored ANSWER will reference the option's
             * text, which is why editing one is a rename and not a re-key — see the docs.
             */
            $table->json('options')->nullable();

            // The order they appear on the Company form. Authored, not alphabetical: a form is
            // read top to bottom and the order is part of the question.
            $table->integer('position')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['help_center_space_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('help_center_company_fields');
    }
};
