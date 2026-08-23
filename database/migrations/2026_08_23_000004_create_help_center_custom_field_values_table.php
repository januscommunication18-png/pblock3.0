<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a Customer or a Company answered to a custom field (P75 §8). TENANT-SCOPED.
 *
 * ONE table for both sides, discriminated by `field_kind` (HC-D54). The two store identical
 * shapes, are always read per-owner, and are written by one mapping engine — two tables would
 * be the same code twice and a branch in the engine over which one to insert into.
 *
 * No foreign key on `field_id` or `owner_id`, because both mean a different table depending on
 * `field_kind`; the cleanup is done by the model's deleting hooks and by the unique key below
 * refusing a second answer to the same question.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_center_custom_field_values', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();

            // `customer` or `company` — which field table `field_id` points into, and which
            // record table `owner_id` points into. The two always move together.
            $table->string('field_kind', 10);

            $table->unsignedBigInteger('field_id');
            $table->unsignedBigInteger('owner_id');

            /*
             * TEXT, and one column for every type.
             *
             * A Multiple Select stores its choices as a JSON array in here and a Time Picker
             * stores a time string; typed columns would mean eight nullable columns of which
             * seven are always null. What a value MEANS is the field's `type`, which is one
             * lookup away and is already the single source for that question (P18).
             */
            $table->text('value')->nullable();

            $table->timestamps();

            // One answer per question per record. Also what makes an upsert possible, which is
            // how the mapping engine writes: it does not know whether an answer already exists.
            $table->unique(['field_kind', 'field_id', 'owner_id'], 'hc_cfv_field_owner_unique');
            $table->index(['field_kind', 'owner_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('help_center_custom_field_values');
    }
};
