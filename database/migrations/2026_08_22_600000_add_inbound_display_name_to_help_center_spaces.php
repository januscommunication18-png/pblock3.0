<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The name a customer sees on mail from this Space (docs/features/help-center.md, P65).
 *
 * "eBay Support" in `eBay Support <inbox-wknbatec@inbound.myprojectblock.dev>`.
 *
 * ## Why it lives on the SPACE and not on the Inbox
 *
 * The inbound ADDRESS is generated per Inbox, and a Space may hold several. The display name is
 * not: the requirement states it as one fact per Space — "Sender Display Name = Space Inbound
 * Email Display Name" — and every customer-facing email from a Space is meant to look like it
 * came from the same team whichever Inbox routed it. Storing it per Inbox would let one Space
 * introduce itself under two names, which is the thing this field exists to prevent.
 *
 * ## Why it is NULLABLE rather than backfilled with the Space name
 *
 * Null means "use the Space name", which is the requirement's own default. Copying the name in
 * would freeze it: renaming the Space from "Support" to "eBay Support" would then leave every
 * outgoing email still signed "Support", and nobody would know which of the two fields to fix.
 * `HelpCenterSpace::senderName()` resolves the fallback at read time so the two stay in step
 * until somebody deliberately separates them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('help_center_spaces', function (Blueprint $table) {
            $table->string('inbound_display_name', 100)->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('help_center_spaces', function (Blueprint $table) {
            $table->dropColumn('inbound_display_name');
        });
    }
};
