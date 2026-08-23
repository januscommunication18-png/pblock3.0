<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Space's tags (docs/features/help-center.md, P14). TENANT-SCOPED.
 *
 * A TABLE, not a JSON column on the settings row — the opposite of the Auto BCC decision one
 * change earlier (P13), and for the opposite reasons. Auto BCC's addresses are a field: read and
 * written whole, never referenced. A tag is an ENTITY: Requests will point at it, reports will
 * group by it, and renaming one must not mean rewriting every row that carries the old spelling.
 * A tag stored as a string in a JSON list is a foreign key waiting to be discovered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_center_tags', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->foreignId('help_center_space_id')->constrained('help_center_spaces')->cascadeOnDelete();

            // As typed — "Billing", "VIP" — because a tag is read by people.
            $table->string('name', 60);

            /*
             * The same name lower-cased, and the half of the pair uniqueness is checked on.
             *
             * Two columns for one word, deliberately. "Billing" and "billing" are one tag, and
             * the only portable way to say so is to store the comparison form: MySQL's default
             * collation would catch it and Postgres would not (D1), so a unique index on `name`
             * alone would mean the rule held on one engine and quietly failed on the other.
             */
            $table->string('name_key', 60);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            /*
             * Scoped to the SPACE, not to the workspace.
             *
             * Two Spaces may each have a "Billing" tag and they are two different tags — one
             * team's vocabulary is not another's. The uniqueness stops the same Space holding
             * the same word twice, which is a list nobody can use.
             *
             * `name_key` rather than `name`, so the check is case-insensitive on every engine
             * while the tag still displays the way somebody typed it.
             */
            $table->unique(['help_center_space_id', 'name_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('help_center_tags');
    }
};
