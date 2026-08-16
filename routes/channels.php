<?php

use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast channel authorization (CLAUDE.md §12)
|--------------------------------------------------------------------------
| Every channel here is PRIVATE and every callback returns a boolean — a channel
| that authorizes by existing is a channel anybody can listen on. §12 requires
| tenant isolation to be enforced in the callback, not assumed from the name.
*/

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

/**
 * `private-tenant.{tenantId}.user.{userId}` — one person's stream inside one workspace.
 *
 * The pattern §12 names, and the shape the Inbox needs: notifications belong to a person, but
 * a person belongs to several workspaces, and what they should hear about depends on which one
 * they are looking at. Both halves are checked:
 *
 *  - the channel is YOURS — the user id must be your own;
 *  - and you are still an ACTIVE member of that workspace.
 *
 * The second is the one that matters after the fact: somebody removed from a workspace keeps a
 * browser tab open, and without this their socket would go on receiving that workspace's
 * notifications until they reloaded.
 */
Broadcast::channel('tenant.{tenantId}.user.{userId}', function ($user, string $tenantId, string $userId) {
    if ((int) $user->id !== (int) $userId) {
        return false;
    }

    return WorkspaceMembership::query()
        ->where('workspace_id', $tenantId)
        ->where('user_id', $user->id)
        ->where('status', WorkspaceMembership::STATUS_ACTIVE)
        ->exists();
});
