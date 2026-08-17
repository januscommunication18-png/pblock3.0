<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Publishing a collection to a public URL (docs/features/wiki.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wiki_collections', function (Blueprint $table) {
            // draft | published | unpublished. Draft is where everything starts: a collection
            // is a working document until somebody decides otherwise.
            $table->string('status', 20)->default('draft')->after('visibility');

            /*
             * The public URL's slug, and the reason this column is nullable.
             *
             * It is generated ON DEMAND and never again: the address is the thing people
             * bookmark, paste into documents and send to customers, so changing it silently
             * breaks every one of those. Nothing in the application writes it twice — see
             * CollectionController::generatePublicUrl.
             *
             * Unique across the WORKSPACE, not globally: the public path carries the workspace
             * slug too, so two workspaces may each have a `handbook` without colliding.
             */
            $table->string('public_slug', 80)->nullable()->after('status');
            $table->timestamp('published_at')->nullable()->after('public_slug');

            $table->unique(['tenant_id', 'public_slug']);
        });
    }

    public function down(): void
    {
        Schema::table('wiki_collections', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'public_slug']);
            $table->dropColumn(['status', 'public_slug', 'published_at']);
        });
    }
};
