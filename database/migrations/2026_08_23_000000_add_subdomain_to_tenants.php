<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tenant's customer-facing subdomain (docs/features/workspace-subdomain.md, P72).
 *
 * `acme` in `https://acme.projectblock.app`. It belongs to the TENANT, not to a Help Center
 * Space or a Client Hub instance, so both products can be served from one host under different
 * routes — `/help`, `/client` — and neither can claim `acme` ahead of the other.
 *
 * ## NULLABLE, and unique anyway
 *
 * Only a workspace running a customer-facing product needs one, and the form only asks when
 * Help Center or Client Hub is switched on. Every workspace created before this has none, and
 * backfilling one from `slug` would hand hundreds of tenants a public hostname they never chose
 * — some of which are reserved names or too short to be legal.
 *
 * MySQL and Postgres both allow repeated NULLs under a unique index, so "no subdomain yet" is
 * not a value that can collide.
 *
 * ## The unique index is the real rule
 *
 * The requirement asks for uniqueness "across all tenants, not just within a workspace", and
 * this is the only place that can actually promise it. The live availability check and the
 * validator both race: two people typing `acme` at the same moment are both told it is free,
 * and both submit. The index is what makes the second one fail instead of creating a duplicate
 * host — the validation exists to make that failure readable, not to prevent it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // 63 is the DNS label limit (RFC 1035) — a longer one could never resolve.
            $table->string('subdomain', 63)->nullable()->unique()->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropUnique(['subdomain']);
            $table->dropColumn('subdomain');
        });
    }
};
