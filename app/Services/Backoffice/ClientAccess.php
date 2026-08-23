<?php

namespace App\Services\Backoffice;

use App\Models\Client;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;

/**
 * Whether a customer may use the product (docs/features/backoffice-clients.md, §17, BC-D3).
 *
 * Much simpler under BC-D7, where a Client IS a user. "Disable Client" now means what the
 * requirement says it means — *prevents the user from accessing every tenant* — because there is
 * exactly one client record per person to consult.
 *
 * The GLOBAL switch is the client's status. The TENANT switch is the membership's status, which
 * is what "Disable Tenant Access" flips, so an administrator can close one workspace to somebody
 * without touching the other five.
 */
class ClientAccess
{
    /** The message §17 asks for, in one place so every caller says the same thing. */
    public const BLOCKED_MESSAGE = 'Your account is currently disabled. Please contact support.';

    /**
     * May this user sign in at all?
     *
     * A user with no client row is allowed: somebody mid-signup who has not joined a tenant yet
     * has no client record, and refusing them would break registration to enforce a rule about
     * an account that does not exist.
     */
    public function allows(User $user): bool
    {
        $client = Client::query()->where('user_id', $user->id)->first();

        return $client === null || $client->allowsAccess();
    }

    /**
     * May this user enter this particular tenant?
     *
     * Two gates, and both must pass:
     *   - the CLIENT is not globally disabled (§ "Disable Client → prevents the user from
     *     accessing every tenant");
     *   - their MEMBERSHIP of this tenant is active (§ "Disable Tenant Access").
     *
     * The second is the reason this method exists rather than reusing `allows()`: the whole
     * point of separating the two actions is that an administrator can revoke one workspace
     * "without accidentally disabling all of a customer's tenant access".
     */
    public function allowsTenant(User $user, Workspace $workspace): bool
    {
        if (! $this->allows($user)) {
            return false;
        }

        $membership = WorkspaceMembership::query()
            ->where('user_id', $user->id)
            ->where('workspace_id', $workspace->id)
            ->first();

        // No membership at all is not this class's business — that is an authorization question
        // the workspace policy answers, and returning false here would disguise it as a
        // suspension.
        return $membership === null || $membership->status !== WorkspaceMembership::STATUS_DISABLED;
    }
}
