<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * project_view_favorites (Views §5.2) — the favourite indicator on the View listing.
 *
 * Its own table because a favourite is per USER: two people looking at the same shared View
 * disagree about whether it is one of theirs, so it cannot be a column on project_views.
 *
 * cascadeOnDelete on both sides: an unfavourite is the only meaning a dangling row could have,
 * and there is no history worth keeping in a star.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_view_favorites', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('project_view_id')->constrained('project_views')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['project_view_id', 'user_id'], 'project_view_favorites_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_view_favorites');
    }
};
