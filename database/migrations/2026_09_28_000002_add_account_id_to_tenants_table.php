<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every workspace belongs to exactly one account
 * (docs/features/tenant-workspace-ownership.md §2/§6).
 *
 * `tenants` is the workspace table (CLAUDE.md §18 D5), so this is the requirement's
 * `workspaces.tenant_id` under the names this codebase already uses.
 *
 * Written in two steps deliberately: the column arrives NULLABLE, every existing workspace is
 * given an account, and only then is it made required. There is no correct default to create it
 * with, so "add it NOT NULL" is not available.
 *
 * The foreign key is declared WITH the column rather than added afterwards, because SQLite —
 * which the test suite runs on — can attach a reference while adding a column but cannot
 * ALTER TABLE ADD CONSTRAINT at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // restrict, never cascade: deleting an account must not silently take live
            // workspaces — and everything inside them — with it. Removing a workspace is
            // WorkspaceDeleter's job and stays an explicit act.
            $table->foreignId('account_id')->nullable()->after('id')
                ->constrained('accounts')->restrictOnDelete();
        });

        $this->backfill();

        Schema::table('tenants', function (Blueprint $table) {
            // Required from here on (§6): a workspace with no account has nothing saying whose
            // it is, and every path that creates one goes through WorkspaceCreator, which
            // always sets it.
            $table->foreignId('account_id')->nullable(false)->change();
        });
    }

    /**
     * One account per person who already owns workspaces (§3), created in the order the
     * workspaces were.
     *
     * The owner is `created_by`; where that user is gone the workspace's own Owner membership
     * names them instead. A workspace with neither — nothing left that says whose it is — gets
     * an ownerless account of its own rather than being folded into somebody else's.
     */
    private function backfill(): void
    {
        $next = 0;
        $accountFor = [];

        $workspaces = DB::table('tenants')->orderBy('created_at')->orderBy('id')
            ->get(['id', 'name', 'created_by']);

        foreach ($workspaces as $workspace) {
            $ownerId = $workspace->created_by ?? DB::table('workspace_memberships')
                ->where('workspace_id', $workspace->id)
                ->where('role', 'owner')
                ->orderBy('id')
                ->value('user_id');

            $owner = $ownerId ? DB::table('users')->where('id', $ownerId)->first(['id', 'name', 'email']) : null;

            if ($owner && isset($accountFor[$owner->id])) {
                DB::table('tenants')->where('id', $workspace->id)
                    ->update(['account_id' => $accountFor[$owner->id]]);

                continue;
            }

            $label = $owner
                ? trim(($owner->name ?: $owner->email).'’s Account')
                : $workspace->name;

            $accountId = DB::table('accounts')->insertGetId([
                'code' => 'AC-'.str_pad((string) ++$next, 6, '0', STR_PAD_LEFT),
                'owner_user_id' => $owner?->id,
                'name' => $label,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($owner) {
                $accountFor[$owner->id] = $accountId;
            }

            DB::table('tenants')->where('id', $workspace->id)->update(['account_id' => $accountId]);
        }
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('account_id');
        });
    }
};
