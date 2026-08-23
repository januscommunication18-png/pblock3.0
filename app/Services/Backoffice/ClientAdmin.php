<?php

namespace App\Services\Backoffice;

use App\Models\BackofficeAuditLog;
use App\Models\BackofficeUser;
use App\Models\Client;
use App\Models\ClientActivity;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\Password;

/**
 * The three administrative actions on a client (docs/features/backoffice-clients.md, §16–§19).
 *
 * A service rather than controller bodies, because each action has to do the same three things
 * and must not be able to do only two: change the state, write the AUDIT row (§24) and write the
 * client ACTIVITY entry (§15). Those are two different tables for two different readers (BC-D5),
 * and a controller that remembered one and forgot the other is the bug this class exists to make
 * impossible.
 */
class ClientAdmin
{
    public function __construct(private readonly BackofficeAudit $audit) {}

    /**
     * §16 — send a reset link to the primary contact.
     *
     * A GLOBAL action: it affects the person's account, not one tenant. The screen says so.
     *
     * The administrator never sees, sets or generates a password. What they trigger is the same
     * email the customer would have requested themselves, on the CUSTOMER broker — this is a
     * customer account, not a Back Office one.
     *
     * Returns false when there is nobody to send to, which the caller reports rather than
     * pretending succeeded.
     */
    public function sendPasswordReset(Client $client, BackofficeUser $actor): bool
    {
        $contact = $client->user;

        if ($contact === null || trim((string) $contact->email) === '') {
            return false;
        }

        Password::broker('users')->sendResetLink(['email' => $contact->email]);

        $this->both(
            $client,
            $actor,
            BackofficeAuditLog::CLIENT_PASSWORD_RESET_REQUESTED,
            ClientActivity::PASSWORD_RESET_REQUESTED,
            'Password reset link sent to '.$contact->email.'.',
            meta: ['contact_email' => $contact->email],
        );

        return true;
    }

    /** §17 — suspend access without deleting anything. */
    public function disable(Client $client, BackofficeUser $actor): void
    {
        $before = $client->status;

        $client->forceFill([
            'status' => Client::STATUS_DISABLED,
            'disabled_at' => now(),
        ])->save();

        $this->both(
            $client, $actor,
            BackofficeAuditLog::CLIENT_DISABLED, ClientActivity::DISABLED,
            'Client disabled. Users can no longer sign in; data is retained.',
            $before, Client::STATUS_DISABLED,
        );
    }

    /** §17 — the other direction. */
    public function enable(Client $client, BackofficeUser $actor): void
    {
        $before = $client->status;

        $client->forceFill([
            'status' => Client::STATUS_ACTIVE,
            'disabled_at' => null,
            // Re-enabling a client that was queued for deletion cancels the queue. Leaving the
            // timestamp would mean a live client silently inside a retention window.
            'pending_deletion_at' => null,
        ])->save();

        $this->both(
            $client, $actor,
            BackofficeAuditLog::CLIENT_ENABLED, ClientActivity::ENABLED,
            'Client re-enabled.',
            $before, Client::STATUS_ACTIVE,
        );
    }

    /**
     * §18–§19 — stage ONE of deletion, and the only stage a button performs.
     *
     * Nothing is removed. The client moves to Pending Deletion, its users stop being able to
     * sign in, and a 30-day retention window opens during which a Super Admin can restore it.
     * Permanent removal is a separate, later, deliberate act — see `Client::purgeableAt()`.
     */
    public function requestDeletion(Client $client, BackofficeUser $actor): void
    {
        $before = $client->status;

        $client->forceFill([
            'status' => Client::STATUS_PENDING_DELETION,
            'pending_deletion_at' => now(),
        ])->save();

        $this->both(
            $client, $actor,
            BackofficeAuditLog::CLIENT_DELETE_REQUESTED, ClientActivity::DELETE_REQUESTED,
            'Deletion requested. Data is retained until '
                .$client->purgeableAt()?->format('M j, Y').' and can be restored until then.',
            $before, Client::STATUS_PENDING_DELETION,
            ['purgeable_at' => $client->purgeableAt()?->toIso8601String()],
        );
    }

    /** §19 — pull it back out of the retention window. */
    public function restore(Client $client, BackofficeUser $actor): void
    {
        $before = $client->status;

        $client->forceFill([
            'status' => Client::STATUS_ACTIVE,
            'pending_deletion_at' => null,
            'disabled_at' => null,
        ])->save();

        $this->both(
            $client, $actor,
            BackofficeAuditLog::CLIENT_RESTORED, ClientActivity::RESTORED,
            'Client restored from pending deletion.',
            $before, Client::STATUS_ACTIVE,
        );
    }

    /* ===== tenant-scoped actions (§ "Client Actions") =================================
       Deliberately separate from everything above, which is global. The requirement's reason is
       explicit: this "prevents accidentally disabling all of a customer's tenant access when the
       administrator only intends to modify one workspace." */

    /** Close ONE tenant to this person, leaving their others alone. */
    public function disableTenantAccess(Client $client, WorkspaceMembership $membership, BackofficeUser $actor): void
    {
        $membership->forceFill(['status' => WorkspaceMembership::STATUS_DISABLED])->save();

        $this->tenantTrail($client, $actor, $membership,
            'Tenant access disabled for '.($membership->workspace->name ?? 'a tenant').'.',
            WorkspaceMembership::STATUS_ACTIVE, WorkspaceMembership::STATUS_DISABLED);
    }

    public function enableTenantAccess(Client $client, WorkspaceMembership $membership, BackofficeUser $actor): void
    {
        $membership->forceFill(['status' => WorkspaceMembership::STATUS_ACTIVE])->save();

        $this->tenantTrail($client, $actor, $membership,
            'Tenant access restored for '.($membership->workspace->name ?? 'a tenant').'.',
            WorkspaceMembership::STATUS_DISABLED, WorkspaceMembership::STATUS_ACTIVE);
    }

    /** Change their role in ONE tenant. */
    public function changeTenantRole(Client $client, WorkspaceMembership $membership, string $role, BackofficeUser $actor): void
    {
        $before = (string) $membership->role;

        $membership->forceFill(['role' => $role])->save();

        $this->tenantTrail($client, $actor, $membership,
            'Role in '.($membership->workspace->name ?? 'a tenant').' changed from '.$before.' to '.$role.'.',
            $before, $role);
    }

    /**
     * Remove them from ONE tenant entirely.
     *
     * The membership row is deleted, which is what "Remove From Tenant" means — unlike Disable,
     * which keeps it so access can be handed back. The activity entry survives it, because the
     * feed hangs off the client rather than the membership.
     */
    public function removeFromTenant(Client $client, WorkspaceMembership $membership, BackofficeUser $actor): void
    {
        $name = $membership->workspace->name ?? 'a tenant';
        $role = (string) $membership->role;

        $this->tenantTrail($client, $actor, $membership,
            'Removed from '.$name.'.', $role, null);

        $membership->delete();
    }

    /** @param  array<string, mixed>  $meta */
    private function tenantTrail(
        Client $client,
        BackofficeUser $actor,
        WorkspaceMembership $membership,
        string $description,
        ?string $old = null,
        ?string $new = null,
    ): void {
        $meta = [
            'scope' => 'tenant',
            'tenant_id' => $membership->workspace_id,
            'tenant_name' => $membership->workspace->name ?? null,
        ];

        $this->audit->record(
            BackofficeAuditLog::CLIENT_TENANT_CHANGED,
            $client->email(),
            $actor,
            true,
            $meta + ['client_id' => $client->id, 'old_value' => $old, 'new_value' => $new],
        );

        ClientActivity::create([
            'client_id' => $client->id,
            'backoffice_user_id' => $actor->id,
            'action' => ClientActivity::TENANT_CHANGED,
            'description' => $description,
            'old_value' => $old,
            'new_value' => $new,
            'meta' => $meta,
        ]);
    }

    /**
     * Both trails, always together.
     *
     * @param  array<string, mixed>  $meta
     */
    private function both(
        Client $client,
        BackofficeUser $actor,
        string $auditAction,
        string $activityAction,
        string $description,
        ?string $old = null,
        ?string $new = null,
        array $meta = [],
    ): void {
        $this->audit->record($auditAction, $client->email(), $actor, true, $meta + [
            'client_id' => $client->id,
            'client_code' => $client->code,
            'old_value' => $old,
            'new_value' => $new,
        ]);

        ClientActivity::create([
            'client_id' => $client->id,
            'backoffice_user_id' => $actor->id,
            'action' => $activityAction,
            'description' => $description,
            'old_value' => $old,
            'new_value' => $new,
            'meta' => $meta === [] ? null : $meta,
        ]);
    }
}
