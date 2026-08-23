<?php

use App\Models\Client;
use App\Models\ClientActivity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A Client is a USER, not a bundle of workspaces (docs/features/backoffice-clients.md, BC-D7).
 *
 * The first model grouped workspaces under an invented entity, which meant somebody who belongs
 * to three tenants appeared as three clients. This regroups on the global user account: one row
 * per person, their tenants reached through `workspace_memberships`.
 *
 * `tenants.client_id` goes with it. Under this model a tenant has MANY clients — everybody in it
 * — so a single foreign key on the tenant cannot express the relationship at all.
 *
 * The old client rows are rebuilt rather than migrated: they were keyed to workspaces and there
 * is no correct one-to-one mapping onto users. They were created by a backfill three commits
 * ago and carry no administrator-authored data, so nothing is lost that anybody typed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            /*
             * The primary relationship (BC-D7). Unique: one client per user, which is the whole
             * point of the change — the database refuses the duplication rather than trusting
             * every future writer to group correctly.
             *
             * Nullable + `nullOnDelete`: a user who deletes their account should not take the
             * administrative record of them with it. The `name` column below survives as the
             * label for exactly that case.
             */
            $table->foreignId('user_id')->nullable()->after('code')
                ->constrained('users')->nullOnDelete();
            $table->unique('user_id');
        });

        Schema::table('clients', function (Blueprint $table) {
            // Replaced by `user_id` — the contact IS the client now.
            $table->dropConstrainedForeignId('primary_contact_id');
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_id');
        });

        $this->rebuild();
    }

    /** One client per distinct user who belongs to at least one tenant. */
    private function rebuild(): void
    {
        // Activities first: they are keyed to the rows about to go.
        DB::table('client_activities')->delete();
        DB::table('clients')->delete();

        $users = DB::table('workspace_memberships')
            ->join('users', 'users.id', '=', 'workspace_memberships.user_id')
            ->groupBy('users.id', 'users.name', 'users.email', 'users.timezone', 'users.created_at')
            ->orderBy('users.id')
            ->get([
                'users.id', 'users.name', 'users.email', 'users.timezone',
                'users.created_at',
                DB::raw('COUNT(*) as tenant_count'),
            ]);

        $n = 0;

        foreach ($users as $user) {
            /*
             * `forceFill`, not `create`.
             *
             * A migration must not depend on the model's `$fillable`, which is a moving target:
             * this ran once with `user_id` absent from that list, mass assignment dropped it
             * without a word, and every client was written with a null user. The predecessor of
             * this file lost `created_at` the same way. `forceFill` cannot be silently ignored,
             * so the data written is the data asked for.
             */
            $client = (new Client)->forceFill([
                'code' => 'CL-'.str_pad((string) ++$n, 6, '0', STR_PAD_LEFT),
                'user_id' => $user->id,
                // A SNAPSHOT for the case where the user row is later deleted. The screens prefer
                // the live user; this is the fallback so a client never renders nameless.
                'name' => $user->name ?: $user->email,
                'timezone' => $user->timezone,
                'status' => Client::STATUS_ACTIVE,
                'created_at' => $user->created_at,
                'updated_at' => now(),
            ]);
            $client->save();

            ClientActivity::create([
                'client_id' => $client->id,
                'action' => ClientActivity::CREATED,
                'description' => 'Client account created for '.$user->email.'.',
                'created_at' => $user->created_at ?? now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->after('id')
                ->constrained('clients')->nullOnDelete();
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->foreignId('primary_contact_id')->nullable()->after('name')
                ->constrained('users')->nullOnDelete();
            $table->dropUnique(['user_id']);
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
