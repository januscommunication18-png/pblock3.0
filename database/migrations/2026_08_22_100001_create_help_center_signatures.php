<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agent and Space-default email signatures (docs/features/help-center.md, P48). TENANT-SCOPED.
 *
 * ONE table for both, and `user_id` is what tells them apart: null is the Space's default
 * signature, not-null is that agent's own signature in that Space.
 *
 * Two tables would have been the obvious reading of the requirement — it describes them in two
 * sections — and it would have meant two schemas, two forms and two save paths for something the
 * product then asks to resolve as a single ordered lookup:
 *
 *     Agent Signature → Space Default Signature → No Signature
 *
 * As one table that is one query with `whereIn('user_id', [$id, null])` and a sort. As two it is
 * a join that exists only because of how the requirement was paragraphed.
 *
 * Scoped to the SPACE, not to the workspace: an agent who works Billing and Customer Support may
 * legitimately sign as two different things, and the requirement is explicit that a Space's
 * configuration must not affect another's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_center_signatures', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->foreignId('help_center_space_id')
                ->constrained('help_center_spaces')->cascadeOnDelete();

            /*
             * NULL = the Space's default signature.
             *
             * `nullOnDelete` would be wrong here — a deleted user's signature must not silently
             * become the Space default, which is what that would do. Cascade: the person is gone,
             * so is the way they signed off.
             */
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();

            $table->boolean('enabled')->default(true);

            /*
             * Name and title are stored rather than read off the user, because a signature is how
             * somebody presents themselves to a CUSTOMER. "Dave" in the account and "David Webby,
             * Customer Success" at the bottom of a support email are both correct, and deriving
             * one from the other would make the second impossible.
             */
            $table->string('name', 120)->nullable();
            $table->string('job_title', 120)->nullable();
            $table->string('company', 120)->nullable();

            // An absolute URL, not an upload: this module has no attachment pipeline yet, and a
            // signature image has to be reachable from the customer's mail client anyway.
            $table->string('avatar_url', 2048)->nullable();

            // The rich-text block, sanitized on the way in (P41).
            $table->longText('content')->nullable();

            $table->timestamps();

            // One signature per person per Space, and one default per Space (`user_id` null).
            $table->unique(['help_center_space_id', 'user_id'], 'hc_signatures_space_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('help_center_signatures');
    }
};
