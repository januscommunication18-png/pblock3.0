<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which client a workspace belongs to (docs/features/backoffice-clients.md, §9).
 *
 * NULLABLE (BC-D2). A workspace created before anybody assigned it a client must keep working,
 * and the customer signup path must not start depending on the Back Office having run. The
 * Clients list simply shows unassigned workspaces nowhere until they are claimed.
 *
 * `nullOnDelete` rather than cascade: deleting a client row must never take live tenant data
 * with it. §19's whole point is that removing a client is a slow, reversible, deliberate act.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->after('id')
                ->constrained('clients')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_id');
        });
    }
};
