<?php

use App\Models\Client;
use App\Models\ClientActivity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Repair, forward-only (docs/features/backoffice-clients.md, BC-D7).
 *
 * Two things went wrong in `120001` and both are worth writing down rather than quietly
 * squashing, because both are traps anybody would fall into again:
 *
 * 1. It called `Client::create()` with `user_id` before that key was in the model's `$fillable`.
 *    Mass assignment DROPPED it silently and every client was written with a null user. Nothing
 *    errored; the column simply stayed empty.
 * 2. Rolling it back to fix that failed halfway — `down()` had already re-added
 *    `tenants.client_id` before erroring — which left the schema half-reverted and the migration
 *    still recorded as run.
 *
 * So this repairs the end state directly instead of unpicking a partial rollback. It is written
 * to be safe from ANY of those intermediate states: every step checks before it acts.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The intended shape: no `primary_contact_id` (the user IS the contact now), and no
        // `tenants.client_id` (a tenant has many clients under this model).
        if (Schema::hasColumn('clients', 'primary_contact_id')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->dropConstrainedForeignId('primary_contact_id');
            });
        }

        if (Schema::hasColumn('tenants', 'client_id')) {
            Schema::table('tenants', function (Blueprint $table) {
                $table->dropConstrainedForeignId('client_id');
            });
        }

        if (! Schema::hasColumn('clients', 'user_id')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->foreignId('user_id')->nullable()->after('code')
                    ->constrained('users')->nullOnDelete();
                $table->unique('user_id');
            });
        }

        $this->rebuild();
    }

    /**
     * One client per distinct user who belongs to at least one tenant.
     *
     * `forceFill` throughout — a migration must not depend on a model's `$fillable`, which is
     * exactly how `120001` lost the user link without a word.
     */
    private function rebuild(): void
    {
        DB::table('client_activities')->delete();
        DB::table('clients')->delete();

        $users = DB::table('workspace_memberships')
            ->join('users', 'users.id', '=', 'workspace_memberships.user_id')
            ->groupBy('users.id', 'users.name', 'users.email', 'users.timezone', 'users.created_at')
            ->orderBy('users.id')
            ->get(['users.id', 'users.name', 'users.email', 'users.timezone', 'users.created_at']);

        $n = 0;

        foreach ($users as $user) {
            $client = (new Client)->forceFill([
                'code' => 'CL-'.str_pad((string) ++$n, 6, '0', STR_PAD_LEFT),
                'user_id' => $user->id,
                'name' => $user->name ?: $user->email,
                'timezone' => $user->timezone,
                'status' => Client::STATUS_ACTIVE,
                'created_at' => $user->created_at,
                'updated_at' => now(),
            ]);
            $client->save();

            (new ClientActivity)->forceFill([
                'client_id' => $client->id,
                'action' => ClientActivity::CREATED,
                'description' => 'Client account created for '.$user->email.'.',
                'created_at' => $user->created_at ?? now(),
            ])->save();
        }
    }

    public function down(): void
    {
        // Nothing. This migration exists to repair a state that should not recur, and a `down()`
        // that recreated the broken shape would be a way back into it.
    }
};
