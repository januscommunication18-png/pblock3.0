<?php

use App\Models\Client;
use App\Models\ClientActivity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every existing workspace becomes its own client (docs/features/backoffice-clients.md, BC-D1).
 *
 * ONE-TO-ONE, deliberately. Grouping by workspace OWNER was the alternative and was rejected on
 * the evidence in this database: 15 workspaces across 9 owners, one address owning six that are
 * plainly test workspaces rather than one company's. A backfill that groups invents companies
 * nobody created — and un-merging two clients is harder than merging two.
 *
 * So this produces clients that are provably correct but deliberately un-grouped, and the
 * grouping is a decision somebody makes later with knowledge this migration does not have.
 *
 * Idempotent: workspaces that already carry a `client_id` are skipped, so re-running adds
 * nothing and creates no duplicates.
 */
return new class extends Migration
{
    public function up(): void
    {
        $workspaces = DB::table('tenants')
            ->whereNull('client_id')
            ->orderBy('created_at')
            ->get(['id', 'name', 'created_by', 'timezone', 'status', 'created_at']);

        foreach ($workspaces as $workspace) {
            $client = Client::create([
                'code' => Client::nextCode(),
                'name' => (string) ($workspace->name ?: 'Unnamed client'),
                'primary_contact_id' => $workspace->created_by,
                'timezone' => $workspace->timezone,
                /*
                 * The workspace's own status carried across where it is one this module knows,
                 * and `active` otherwise. A workspace whose status is a word the Client vocabulary
                 * has never heard of should not become a client in an invalid state.
                 */
                'status' => in_array($workspace->status, Client::STATUSES, true)
                    ? $workspace->status
                    : Client::STATUS_ACTIVE,
            ]);

            /*
             * "Client since" is the WORKSPACE's birthday, not this migration's.
             *
             * Written with `forceFill` after the insert rather than passed to `create()`:
             * `created_at` is not in `$fillable`, so mass assignment silently drops it and every
             * backfilled client claimed to have signed up on the day this ran. Silently — which
             * is why it is called out here rather than fixed quietly.
             */
            $client->forceFill(['created_at' => $workspace->created_at])->save();

            DB::table('tenants')->where('id', $workspace->id)->update(['client_id' => $client->id]);

            ClientActivity::create([
                'client_id' => $client->id,
                'action' => ClientActivity::CREATED,
                'description' => 'Client account created from workspace "'.$workspace->name.'".',
                'created_at' => $workspace->created_at ?? now(),
            ]);
        }
    }

    public function down(): void
    {
        // The link first, then the rows — a client with tenants still pointing at it cannot be
        // removed while the foreign key stands.
        DB::table('tenants')->update(['client_id' => null]);
        DB::table('client_activities')->delete();
        DB::table('clients')->delete();
    }
};
