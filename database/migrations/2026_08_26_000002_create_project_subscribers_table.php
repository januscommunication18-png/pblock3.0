<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * project_subscribers (General spec §13) — members who receive this project's notifications.
 *
 * Deliberately separate from `project_members`: subscribing is about who wants to hear about
 * the project, not who may act in it. A workspace admin may subscribe without being a member,
 * and a member may work in a project without wanting every notification.
 *
 * TENANT-SCOPED via the project it hangs off; the pair is unique, so subscribing twice is a
 * no-op rather than a duplicate notification.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_subscribers', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['project_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_subscribers');
    }
};
