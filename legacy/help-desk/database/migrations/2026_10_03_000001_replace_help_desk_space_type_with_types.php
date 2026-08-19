<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A space's type becomes typeS — several free-text values instead of one from a fixed list.
 *
 * The original column was one of six keys chosen from a dropdown. In practice a space is more
 * than one thing at once — "Partner Support" that is also a "Business Unit", a regional desk
 * that is both retail and customer support — and the six were never going to be everybody's
 * six. So the field is now a list the reader writes themselves.
 *
 * JSON rather than a `help_desk_space_types` table plus a pivot (decision H45): these are
 * labels, not entities. Nothing points at them, nothing is scoped by them, and no screen asks
 * "which spaces are tagged X?" — two tables and a join to store what is honestly a list of
 * strings on one row would be the overengineering CLAUDE.md §6 warns about. When a type earns
 * behaviour of its own, it earns a table with it.
 *
 * Existing values are carried across as their LABELS, not their keys: the column stops holding
 * an identifier the code understands and starts holding text a person typed, so
 * `customer_support` would arrive on screen as a slug nobody wrote.
 */
return new class extends Migration
{
    /** The six the old column allowed, and what each one reads as. */
    private const LEGACY = [
        'customer_support' => 'Customer Support',
        'partner_support' => 'Partner Support',
        'retail_support' => 'Retail Support',
        'internal_support' => 'Internal Support',
        'business_unit' => 'Business Unit',
        'other' => 'Other',
    ];

    public function up(): void
    {
        Schema::table('help_desk_spaces', function (Blueprint $table) {
            // Nullable, and null means "none given" — an empty array would be a second way of
            // saying the same thing, and two of those is one too many.
            $table->json('types')->nullable()->after('description');
        });

        DB::table('help_desk_spaces')
            ->whereNotNull('type')
            ->orderBy('id')
            ->each(function ($space) {
                DB::table('help_desk_spaces')->where('id', $space->id)->update([
                    'types' => json_encode([self::LEGACY[$space->type] ?? $space->type]),
                ]);
            });

        Schema::table('help_desk_spaces', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }

    public function down(): void
    {
        Schema::table('help_desk_spaces', function (Blueprint $table) {
            $table->string('type', 30)->nullable()->after('description');
        });

        // Back to one value, and only when it is one the old column could hold. A free-text
        // type has no key to become, so it is dropped rather than written as something the
        // old validation would have refused.
        $keys = array_flip(self::LEGACY);

        DB::table('help_desk_spaces')
            ->whereNotNull('types')
            ->orderBy('id')
            ->each(function ($space) use ($keys) {
                $first = (array) json_decode((string) $space->types, true);

                DB::table('help_desk_spaces')->where('id', $space->id)->update([
                    'type' => $keys[$first[0] ?? ''] ?? null,
                ]);
            });

        Schema::table('help_desk_spaces', function (Blueprint $table) {
            $table->dropColumn('types');
        });
    }
};
