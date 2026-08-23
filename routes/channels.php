<?php

use App\Models\HelpCenterSpace;
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

/**
 * `private-tenant.{tenantId}.help-center.space.{spaceId}` — one Space's live ticket stream (P67).
 *
 * The Space rather than the person, because a new customer reply concerns whoever is looking at
 * that Inbox — usually several people, and not necessarily the assignee.
 *
 * Three checks, and all three earn their place:
 *
 *  - an ACTIVE member of that workspace, exactly as the user channel above requires. Somebody
 *    removed from a workspace with a tab still open must stop hearing about it, and a socket
 *    that was authorized once would otherwise go on delivering until they reloaded;
 *  - the Space actually belongs to that workspace — without it, a member of workspace A could
 *    listen on `tenant.A.help-center.space.{a space in B}` and be told about B's tickets;
 *  - and the Space policy's own answer, so who may LISTEN is decided by the same code that
 *    decides who may READ (CLAUDE.md §12).
 *
 * `withoutGlobalScopes()` is required rather than tidy: a websocket subscribe carries no tenancy
 * context, so `BelongsToTenant`'s scope would find nothing and every subscribe would be refused.
 * The `tenant_id` is then checked by hand, which is what that scope would have done.
 */
Broadcast::channel(
    'tenant.{tenantId}.help-center.space.{spaceId}',
    function ($user, string $tenantId, string $spaceId) {
        $member = WorkspaceMembership::query()
            ->where('workspace_id', $tenantId)
            ->where('user_id', $user->id)
            ->where('status', WorkspaceMembership::STATUS_ACTIVE)
            ->exists();

        if (! $member) {
            return false;
        }

        $space = HelpCenterSpace::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->find($spaceId);

        return $space !== null && $user->can('view', $space);
    },
);
