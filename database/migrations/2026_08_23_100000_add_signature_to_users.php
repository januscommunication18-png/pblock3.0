<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An agent's personal signature (docs/features/help-center.md, P74).
 *
 * On USERS, not on a workspace or a Space. The requirement is explicit — "save the signature
 * against the user profile" — and it is right: a person's sign-off follows them, and an agent
 * who works three Spaces should not have to write it three times.
 *
 * That is a real change from P48, which stored an agent's signature per Space
 * (`help_center_signatures.user_id` alongside `help_center_space_id`). Those rows still resolve,
 * below this one — see SignatureResolver.
 *
 * ## Plain text, not HTML
 *
 * Stored as the agent typed it and converted to HTML when it is rendered
 * (`RichTextSanitizer::fromPlainText`). Two reasons:
 *
 *   - the requirement asks for "a rich-text OR formatted text field", and its own example is
 *     three plain lines — `Thanks, / Rohit Philip / Customer Support`;
 *   - the account dialog is plain JS, not Vue, so the project's rich editor (`pg-editor`, a Vue
 *     component) cannot be mounted there without turning that dialog into a Vue island for one
 *     field. A textarea round-trips its own content exactly, which HTML in a textarea does not.
 *
 * If this becomes a rich field later it wants a second column or a format flag rather than a
 * change of meaning for this one — silently reinterpreting stored plain text as markup would
 * turn somebody's `<` into a broken tag.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('signature')->nullable()->after('display_name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('signature');
        });
    }
};
