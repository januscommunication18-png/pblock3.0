<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * project_page_versions — the history of a page's content.
 *
 * A row is a state the document was saved in, not a diff: storing the whole body means
 * restoring is a copy rather than a replay, and one corrupt row cannot poison every version
 * after it. Pages are documents, not streams — the storage is worth the simplicity.
 *
 * Versions are COALESCED rather than written per save. The editor autosaves after every pause
 * in typing, so a row per save would be thousands of near-identical rows for one afternoon's
 * work; instead consecutive saves by the same author inside a short window update the same
 * version. See App\Services\PageVersioner.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_page_versions', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('project_page_id')->constrained('project_pages')->cascadeOnDelete();
            // The document as it stood. Title and status travel too: "what did this page look
            // like" includes what it was called and whether it was published.
            $table->string('title', 200);
            $table->longText('content')->nullable();
            $table->string('status', 20)->default('draft');
            $table->foreignId('edited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            // Drives the history list: one page's versions, newest first.
            $table->index(['project_page_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_page_versions');
    }
};
