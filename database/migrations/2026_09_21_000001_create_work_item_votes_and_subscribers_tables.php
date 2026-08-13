<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The work item detail toolbar's two missing controls (POC html/work-items.html):
 * upvote / downvote, and subscribe.
 *
 * Both are TENANT-SCOPED (CLAUDE.md §7) and both are one row per person per work item, which
 * the unique pairs enforce at the database rather than leaving to the code that writes them:
 * voting twice is changing your vote, and subscribing twice is still one subscription.
 *
 * `work_item_subscribers` is deliberately NOT the same thing as `project_subscribers`. That
 * one is "tell me about this project"; this one is "tell me about this work item". Someone
 * following one noisy item does not want the whole project, and vice versa — see
 * docs/features/work-item-toolbar.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_item_votes', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('work_item_id')->constrained('work_items')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // 'up' or 'down'. One column rather than two tables: a person holds ONE opinion,
            // and switching sides is an update, never an insert plus a delete that could half
            // fail and leave them counted on both.
            $table->string('value', 4);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['work_item_id', 'user_id']);
            // Counting a work item's votes by side, which is what every row payload needs.
            $table->index(['work_item_id', 'value']);
        });

        Schema::create('work_item_subscribers', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('work_item_id')->constrained('work_items')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['work_item_id', 'user_id']);
            // "What am I following?" — the read Your Work would make of this.
            $table->index(['user_id', 'work_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_item_subscribers');
        Schema::dropIfExists('work_item_votes');
    }
};
