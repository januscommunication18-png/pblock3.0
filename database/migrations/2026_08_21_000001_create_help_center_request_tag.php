<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which tags a Request carries (docs/features/help-center.md, P28). TENANT-SCOPED.
 *
 * P14 built a Space's tag vocabulary and predicted this table in its own migration note:
 * "Requests will point at it, reports will group by it". Nothing pointed at it until now — the
 * tags existed, and no Request could wear one.
 *
 * A pivot rather than a JSON column on the Request, for the reason P14 gave for tags being a
 * table at all: renaming "Billing" must not mean rewriting every row that carries the old
 * spelling, and grouping a report by tag must not mean parsing JSON.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_center_request_tag', function (Blueprint $table) {
            $table->id();

            /*
             * Carried on the pivot too, although both sides already have it.
             *
             * Every other table in this module is scoped the same way, and a join table that is
             * the one exception is the one place a cross-tenant read can be written by accident.
             */
            $table->string('tenant_id')->index();

            $table->foreignId('help_center_request_id')
                ->constrained('help_center_requests')->cascadeOnDelete();

            /*
             * Deleting a TAG takes it off every Request that wore it.
             *
             * P14 deliberately has no rename — a tag is created and deleted — so a cascade here
             * is the whole answer to "what happens to the Requests?". The alternative, keeping
             * orphan rows pointing at nothing, is a list of tags that cannot be rendered.
             */
            $table->foreignId('help_center_tag_id')
                ->constrained('help_center_tags')->cascadeOnDelete();

            // One Request cannot carry the same tag twice — a chip drawn twice is a bug, and
            // the database is the only place that can promise it.
            $table->unique(['help_center_request_id', 'help_center_tag_id'], 'hc_request_tag_unique');

            // Reading a tag's Requests is the reporting direction P14 anticipated.
            $table->index(['tenant_id', 'help_center_tag_id']);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('help_center_request_tag');
    }
};
