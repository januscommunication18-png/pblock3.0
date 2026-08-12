<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The project's chosen cover gradient (PRJ-027).
 *
 * The Edit Project modal has offered gradient swatches all along, but there was nowhere to
 * put the answer: `cover_gradient` was neither a column nor an accessor, so the posted value
 * was dropped by validation and `$project->cover_gradient` was always null. Every coverless
 * card therefore fell back to the first preset, and picking a different one appeared to do
 * nothing.
 *
 * Nullable, and null keeps the old behaviour: the client falls back to the first preset, so
 * existing projects look exactly as they did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('cover_gradient', 255)->nullable()->after('cover_url');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('cover_gradient');
        });
    }
};
