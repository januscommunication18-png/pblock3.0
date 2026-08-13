<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The profile banner's fallback, to match the Add Project cover (Account §1).
 *
 * A project cover is a gradient until someone uploads an image, and the gradient is a choice
 * from a fixed palette rather than a random one — so the tile looks deliberate either way. The
 * profile banner now works the same, which is what makes the two read as the same control.
 *
 * The gradient is stored rather than derived, unlike the coverless PROJECT CARD which hashes
 * the identifier: a card picks for you, but here the user picks, and a choice has to be kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'cover_gradient')) {
                $table->string('cover_gradient', 191)->nullable()->after('cover_url');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'cover_gradient')) {
                $table->dropColumn('cover_gradient');
            }
        });
    }
};
