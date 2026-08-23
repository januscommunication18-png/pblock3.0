<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Space's outgoing email templates (docs/features/help-center.md, P48). TENANT-SCOPED.
 *
 * One row per (Space, type), and a MISSING row is meaningful: it means "use the packaged
 * default" from `config('help-center.email_templates')`. That single decision is what makes
 * three other things fall out for free —
 *
 *   a brand-new Space sends sensible mail with nothing configured and nothing seeded,
 *   "Restore Default Template" is a DELETE rather than a copy of the default text, and
 *   improving a default improves every Space that never overrode it.
 *
 * The alternative — seeding three rows per Space on creation — would freeze today's wording into
 * every Space ever made, and leave "restore" meaning "restore to whatever we shipped the day
 * this Space was created".
 *
 * The requirement's "Templates belong to the individual Help Desk Space" is the unique key:
 * there is no workspace-level template and no inheritance between Spaces.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_center_email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->foreignId('help_center_space_id')
                ->constrained('help_center_spaces')->cascadeOnDelete();

            // `auto_response`, `agent_reply` or `ticket_layout` — the keys of the config array.
            $table->string('type', 32);

            $table->string('name', 120);

            // NULL on the layout, which has no subject of its own: it is what a message is put
            // inside, not a message.
            $table->string('subject', 255)->nullable();

            // The editor's HTML, sanitized on the way in like every other rich field (P41).
            $table->longText('body');

            /*
             * The requirement's Enabled/Disabled toggle.
             *
             * Only meaningful where `can_disable` is true in config. The agent reply template
             * "should remain available whenever an agent replies", so its flag is ignored rather
             * than enforced as a special case in four different files.
             */
            $table->boolean('enabled')->default(true);

            $table->timestamps();

            // One template of each type per Space — the whole scoping rule, as a constraint.
            $table->unique(['help_center_space_id', 'type'], 'hc_email_templates_space_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('help_center_email_templates');
    }
};
