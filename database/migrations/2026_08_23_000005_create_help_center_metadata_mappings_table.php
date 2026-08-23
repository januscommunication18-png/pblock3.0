<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket Metadata Mapping (docs/features/help-center.md, P75 §3–§4). TENANT-SCOPED.
 *
 * One row per mapping: "this field on an incoming request goes to that field on the Customer or
 * the Company". ROWS and not a JSON blob on the settings row (HC-D56) — each one is edited,
 * reordered and switched off on its own, and a destination of Custom Field points at a field by
 * id, which is a foreign key a blob cannot carry.
 *
 * Scoped to the Space, like the field lists it draws its destinations from: what one team reads
 * off an incoming ticket is not what another does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_center_metadata_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->foreignId('help_center_space_id')->constrained('help_center_spaces')->cascadeOnDelete();

            // One of `help-center.mapping_sources`. A string rather than an enum column: a new
            // source should be a config entry, the rule the whole module follows.
            $table->string('source', 40);

            /*
             * The name of the payload key, for the one source that needs a second answer —
             * `custom` (Custom / Integration Field). Null for every other source, whose key is
             * the source itself.
             */
            $table->string('source_key', 80)->nullable();

            // `customer` or `company`.
            $table->string('record_type', 10);

            // An attribute key from `mapping_destinations`, or the literal `custom_field`.
            $table->string('destination', 40);

            /*
             * Which custom field, when `destination` is `custom_field`. No foreign key: it
             * points into `help_center_customer_fields` or `help_center_company_fields`
             * depending on `record_type`, so the constraint has no single table to name. The
             * form request checks it belongs to this Space and this record type, and
             * `MetadataMapping::destinationField()` returns null if it has since been deleted —
             * a mapping whose field is gone is skipped, not fatal.
             */
            $table->unsignedBigInteger('custom_field_id')->nullable();

            // Off, not deleted (§4's "Enable/Disable individual mappings"). A mapping somebody
            // paused is a mapping they mean to come back to.
            $table->boolean('is_active')->default(true);

            // Authored order. It is also APPLICATION order — two mappings writing the same
            // destination resolve top-down, and that has to be something a person can see.
            $table->integer('position')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // Named, because the generated name for this table plus these two columns is over
            // MySQL's 64-character identifier limit.
            $table->index(['help_center_space_id', 'position'], 'hc_mapping_space_position_index');

            /*
             * One mapping per destination per Space.
             *
             * Two rows writing Customer → Email from two different sources is not a
             * configuration with a meaning; it is a race whose winner depends on `position`.
             * Refusing it in the schema is clearer than picking one at runtime.
             *
             * `custom_field_id` is part of the key because `custom_field` is a destination that
             * legitimately repeats — once per field.
             */
            $table->unique(
                ['help_center_space_id', 'record_type', 'destination', 'custom_field_id'],
                'hc_mapping_destination_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('help_center_metadata_mappings');
    }
};
