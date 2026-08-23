<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Space's Customer custom fields (docs/features/help-center.md, P75 §2). TENANT-SCOPED.
 *
 * The mirror of `help_center_company_fields`, column for column, and a separate table rather
 * than a `kind` discriminator on that one (HC-D53): the company table already exists, already
 * has live rows, and is already read by a controller, a form request and a modal. Adding a
 * discriminator would mean migrating those rows to gain nothing — the two lists are never read
 * together.
 *
 * The VALUES do share one table (`help_center_custom_field_values`), because nothing reads them
 * apart and the mapping engine would otherwise have to branch on which table to write.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_center_customer_fields', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->foreignId('help_center_space_id')->constrained('help_center_spaces')->cascadeOnDelete();

            $table->string('name', 60);

            // One of `help-center.company_field_types` — the SAME vocabulary. A Customer field
            // and a Company field are the same eight shapes, and two identical lists in config
            // would be two places to add the ninth.
            $table->string('type', 20);

            $table->boolean('is_required')->default(false);

            // Disable, not delete: values already recorded against a retired field stay
            // meaningful. Same rule as the Company fields it mirrors.
            $table->boolean('is_active')->default(true);

            $table->json('options')->nullable();
            $table->integer('position')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['help_center_space_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('help_center_customer_fields');
    }
};
