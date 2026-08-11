<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Project identifiers become lower case.
 *
 * They were stored upper case ("WEBSITERED"); the product convention is now lower case
 * ("websitered"). Existing rows are converted so stored data and every new project agree —
 * a display-only change would leave the database saying one thing and the screen another.
 *
 * Uniqueness is per workspace and case-insensitive in practice, so lowering cannot introduce
 * a collision that was not already one. The guard below skips any row that would collide
 * anyway rather than failing the migration on data nobody can fix from here.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('projects')->orderBy('id')->get(['id', 'tenant_id', 'identifier']) as $project) {
            $lower = strtolower((string) $project->identifier);

            if ($lower === $project->identifier) {
                continue;
            }

            $taken = DB::table('projects')
                ->where('tenant_id', $project->tenant_id)
                ->where('identifier', $lower)
                ->where('id', '<>', $project->id)
                ->exists();

            if ($taken) {
                continue;
            }

            DB::table('projects')->where('id', $project->id)->update(['identifier' => $lower]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('projects')->orderBy('id')->get(['id', 'identifier']) as $project) {
            DB::table('projects')->where('id', $project->id)
                ->update(['identifier' => strtoupper((string) $project->identifier)]);
        }
    }
};
