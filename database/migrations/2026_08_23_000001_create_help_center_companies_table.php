<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The organisation a customer writes in from (docs/features/help-center.md, P75 §7–§8).
 * TENANT-SCOPED.
 *
 * WORKSPACE-scoped, not Space-scoped, although the requirement draws `Space → Companies`
 * (HC-D51). `help_center_customers` is already workspace-scoped, and a workspace-scoped
 * Customer pointing at a Space-scoped Company is a relationship that cannot hold — the same
 * person writing to two Spaces would need two Companies for one employer. Space-scoping would
 * also put one Acme row in every Space, which is the duplication the requirement's own
 * acceptance criteria forbid.
 *
 * Custom FIELDS stay Space-scoped (`help_center_company_fields` already is): the field list is
 * a Space's questions, while the record is the workspace's answer about a company.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_center_companies', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();

            $table->string('name');

            /*
             * The email domain, normalised to lower case by the model.
             *
             * This is the identifier that does the work: `john@acme.com` arriving with no
             * company information at all still resolves to Acme when a Company holds
             * `acme.com`, which is the requirement's worked example (§7).
             */
            $table->string('domain')->nullable();

            $table->string('phone', 40)->nullable();

            // The id this company has in whatever system is the customer's source of truth —
            // a CRM, a billing system, the integration that opened the ticket.
            $table->string('external_id', 100)->nullable();

            // JSON rather than a pivot: tags on a Company are labels read whole with the record
            // and nothing joins to them. The Request's tags are a table because they ARE joined.
            $table->json('tags')->nullable();

            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();

            // Null when the mapping engine created it — which is the usual case. A person only
            // appears here for a Company somebody added by hand.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            /*
             * The two uniqueness promises the requirement makes, enforced by the DATABASE and
             * not only by the matcher.
             *
             * Two inbound emails from the same new domain can be ingested concurrently by two
             * queue workers, both find nothing, and both create Acme. A unique index turns that
             * into an insert that fails and is retried as a match; a check in PHP does not.
             *
             * Nullable columns: MySQL and MariaDB allow repeated NULLs in a unique index, which
             * is exactly right here — "no domain recorded" is not an identity.
             */
            $table->unique(['tenant_id', 'domain']);
            $table->unique(['tenant_id', 'external_id']);
            $table->index(['tenant_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('help_center_companies');
    }
};
