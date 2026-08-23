<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The client activity timeline (docs/features/backoffice-clients.md, §15). CENTRAL.
 *
 * SEPARATE from `backoffice_audit_logs` (BC-D5). They answer different questions for different
 * readers: the audit log is "what did an administrator do", and every row has an administrator.
 * This is "what happened to this client", which includes things no administrator did — a
 * workspace created, an application enabled by the customer themselves. Folding them together
 * would mean both queries filtering the other's rows out forever.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();

            // Null when the customer did it, or when it happened by itself. The feed then says
            // "System" rather than crediting whoever happened to be looking.
            $table->foreignId('backoffice_user_id')->nullable()
                ->constrained('backoffice_users')->nullOnDelete();

            $table->string('action', 60);

            // The sentence the feed renders. Composed at write time, because the values it names
            // ("Starter → Growth") are true THEN — a description rebuilt later from current data
            // would quietly rewrite history.
            $table->string('description');

            $table->string('old_value')->nullable();
            $table->string('new_value')->nullable();
            $table->json('meta')->nullable();

            // Written once, never edited — the same reasoning as the audit log.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['client_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_activities');
    }
};
