<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * External members of one collection (docs/features/wiki-external-guests.md).
 *
 * A guest is an ADDRESS WITH A TOKEN against ONE collection — no `users` row, no
 * `workspace_memberships` row, no presence in any picker or mention list. Deleting the row is
 * the entire act of revocation, which is why revocation here cannot half-happen.
 *
 * `wiki_collection_id` is on the guest rather than a pivot, and the token resolves to exactly one
 * collection: "only the collection they were invited to" is then enforced by the shape of the
 * data rather than by a filter somebody could forget to apply.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wiki_collection_guests', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('wiki_collection_id')->constrained('wiki_collections')->cascadeOnDelete();

            // What the administrator typed. A list of bare addresses is unreadable, and this is
            // the only name anybody will ever have for these people.
            $table->string('name', 120);
            $table->string('email');

            /*
             * A string, not a boolean. The modal presents it as a choice, and password / SSO are
             * the obvious next values; a boolean would have to be migrated away to add the second.
             */
            $table->string('login_method', 20)->default('magic_link');

            /*
             * The SHA-256 of the raw token. The raw value exists only long enough to build the
             * email and is never stored or logged — the same handling `workspace_invitations`
             * gives its tokens, so a database dump is not a set of working links.
             */
            $table->string('token', 64)->unique();

            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            // When the link was last SENT, so the list can say "Resent 2h ago".
            $table->timestamp('invited_at')->nullable();
            // Never opened is a fact worth showing: "I sent it, did they read it?" is the
            // question this list actually gets asked.
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            // One address, one row: re-inviting somebody resends rather than making a second.
            $table->unique(['wiki_collection_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wiki_collection_guests');
    }
};
