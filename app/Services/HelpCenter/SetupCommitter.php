<?php

namespace App\Services\HelpCenter;

use App\Models\HelpCenterInbox;
use App\Models\HelpCenterSetupDraft;
use App\Models\HelpCenterSpace;
use App\Models\HelpCenterSpaceMember;
use App\Models\HelpCenterSpaceSettings;
use App\Models\HelpCenterStatus;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMembership;
use App\Services\WorkspaceInviter;
use Illuminate\Support\Facades\DB;

/**
 * Step 6 — turning a draft into a real Help Desk (docs/features/help-center.md, P2 §28).
 *
 * This is the ONLY place the six-step wizard writes anything outside its draft (HC-D11), and it
 * writes all of it or none of it: P2 §29 requires that a failed submission does not silently
 * discard the configuration, so the transaction rolls back and **the draft is left intact** for
 * the user to try again.
 *
 * Invitations are the one thing deliberately outside that transaction — see invite().
 */
class SetupCommitter
{
    public function __construct(
        private readonly SetupDraftStore $drafts,
        private readonly InboundAddressGenerator $inbound,
        private readonly WorkspaceInviter $inviter,
    ) {}

    /**
     * Create the Space, its support group, its Inbox, its workflow and its settings.
     *
     * Returns the Inbox, because P2 §28's last instruction is to open it.
     */
    public function commit(Workspace $workspace, User $actor, HelpCenterSetupDraft $draft): HelpCenterInbox
    {
        $payload = $this->drafts->payload($draft);

        /** @var HelpCenterInbox $inbox */
        [$space, $inbox, $pending] = DB::transaction(function () use ($workspace, $actor, $payload) {
            $space = $this->createSpace($workspace, $actor, $payload[SetupDraftStore::SPACE]);

            $this->createStatuses($workspace, $space, $payload[SetupDraftStore::WORKFLOW]);
            $this->createSettings($workspace, $space, $payload[SetupDraftStore::SETTINGS]);

            $inbox = $this->createInbox($workspace, $actor, $space, $payload[SetupDraftStore::INBOX]);
            $pending = $this->createMembers($workspace, $actor, $space, $payload[SetupDraftStore::TEAM]);

            return [$space, $inbox, $pending];
        });

        /*
         * Invitations happen AFTER the transaction, on purpose.
         *
         * WorkspaceInviter sends the email with `sendNow` and opens its own transaction per
         * invitation. Calling it inside ours would nest transactions and, worse, would let a
         * later rollback "undo" invitations whose emails have already landed in somebody's
         * inbox — which is not something a database can undo.
         *
         * So the Space exists first, and inviting is a follow-on step whose failure leaves a
         * working Help Desk with a member row still marked pending, rather than no Help Desk.
         */
        $this->invite($workspace, $actor, $pending);

        // The run is finished; the draft has served its purpose.
        $this->drafts->discard($draft);

        return $inbox;
    }

    /** Step 1 (P2 §4-§6). */
    /** @param  array<string, mixed>  $data */
    private function createSpace(Workspace $workspace, User $actor, array $data): HelpCenterSpace
    {
        return HelpCenterSpace::create([
            'tenant_id' => $workspace->id,
            'name' => trim((string) $data['name']),
            'description' => $this->nullIfBlank($data['description'] ?? null),
            // NULL when nobody typed one (P65) — `senderName()` falls back to the Space name at
            // read time, so the two stay in step through a later rename.
            'inbound_display_name' => $this->nullIfBlank($data['inbound_display_name'] ?? null),
            'types' => HelpCenterSpace::normalizeTypes((array) ($data['types'] ?? [])),
            'department_groups' => HelpCenterSpace::normalizeTypes((array) ($data['department_groups'] ?? [])),
            'lead_user_id' => $data['lead_user_id'],
            'created_by' => $actor->id,
            'position' => (int) HelpCenterSpace::query()->max('position') + 1,
        ]);
    }

    /**
     * Step 4 (P2 §12-§16).
     *
     * The order and the system rules are NORMALIZED rather than trusted from the client, by
     * `WorkflowStatusPayload::ordered()` — the same function Settings → Workflow's editor writes
     * through (P16), so a workflow built by the wizard and one edited afterwards cannot end up
     * obeying two different definitions of `Open → custom → Closed`.
     *
     * @param  array<string, mixed>  $data
     */
    private function createStatuses(Workspace $workspace, HelpCenterSpace $space, array $data): void
    {
        foreach (WorkflowStatusPayload::ordered((array) ($data['statuses'] ?? [])) as $row) {
            HelpCenterStatus::create([
                'tenant_id' => $workspace->id,
                'help_center_space_id' => $space->id,
                'name' => $row['name'],
                'color' => $this->color($row['color'] ?? null),
                'responsibility' => $row['responsibility'],
                'waiting_on' => $row['waiting_on'],
                // The System Category (P54). The wizard's cards carry it too, so a Space is
                // categorised from the moment it is created rather than from its first edit.
                'system_category' => $row['system_category'],
                'is_active' => $row['is_active'],
                'is_default' => $row['is_default'],
                'system_key' => $row['system_key'],
                'position' => $row['position'],
                // Filtered against this Space's actual members, which is a question about THIS
                // workspace and so cannot live in the shared payload rules.
                'default_assignees' => $this->memberIds($row['default_assignees'] ?? []),
            ]);
        }
    }

    /** Step 5 (P2 §17-§24). */
    /** @param  array<string, mixed>  $data */
    private function createSettings(Workspace $workspace, HelpCenterSpace $space, array $data): void
    {
        $enabled = (bool) ($data['reassign_enabled'] ?? false);

        $minutes = ((int) ($data['reassign_hours'] ?? 0) * 60) + (int) ($data['reassign_minutes'] ?? 0);

        $bcc = (bool) ($data['auto_bcc_enabled'] ?? false);

        HelpCenterSpaceSettings::create([
            'tenant_id' => $workspace->id,
            'help_center_space_id' => $space->id,
            'metadata' => $this->metadata((array) ($data['metadata'] ?? [])),

            'auto_bcc_enabled' => $bcc,
            /*
             * Off means no address stored: a disabled setting should not leave a live address
             * behind that a later toggle silently reactivates.
             *
             * The wizard still asks for ONE address — a first run is not the moment to build a
             * list — and it is written as a one-element list because that is what the column
             * holds now (P13). More are added on Settings → Auto BCC.
             */
            'auto_bcc_emails' => $bcc
                ? array_values(array_filter([mb_strtolower(trim((string) ($data['auto_bcc_email'] ?? '')))]))
                : [],

            'reassign_enabled' => $enabled,
            'reassign_after_minutes' => $enabled ? $minutes : null,
            'reassign_destination' => in_array(
                $data['reassign_destination'] ?? null,
                HelpCenterSpaceSettings::destinations(),
                true,
            ) ? $data['reassign_destination'] : HelpCenterSpaceSettings::DESTINATION_UNASSIGNED,

            'auto_follow_mentions' => (bool) ($data['auto_follow_mentions'] ?? true),
        ]);
    }

    /** Step 3 (P2 §9, §10) — Phase 1's Inbox, unchanged in shape. */
    /** @param  array<string, mixed>  $data */
    private function createInbox(Workspace $workspace, User $actor, HelpCenterSpace $space, array $data): HelpCenterInbox
    {
        $inbox = HelpCenterInbox::create([
            'tenant_id' => $workspace->id,
            'help_center_space_id' => $space->id,
            'name' => trim((string) $data['name']),
            'inbound_id' => $this->reserveInboundId($data['inbound_id'] ?? null),
            // Created through the wizard, so there is no outstanding step for it.
            'setup_completed_at' => now(),
            'created_by' => $actor->id,
            'position' => 1,
        ]);

        foreach ((array) ($data['addresses'] ?? []) as $address) {
            $email = mb_strtolower(trim((string) ($address['email'] ?? '')));

            if ($email === '') {
                continue;
            }

            $name = trim((string) ($address['name'] ?? ''));

            $inbox->emailAddresses()->create([
                'tenant_id' => $workspace->id,
                'email' => $email,
                'name' => $name === '' ? null : $name,
                'created_by' => $actor->id,
            ]);
        }

        return $inbox;
    }

    /**
     * The identifier reserved at Step 3, re-checked (HC-D12).
     *
     * A draft started yesterday holds a token that was free yesterday. Between then and now it
     * could have been issued to another workspace's Inbox, and the unique index would turn that
     * into a 500 at the worst possible moment — the click that creates everything. So the
     * reservation is verified, and a fresh token is drawn if it has gone.
     */
    private function reserveInboundId(?string $reserved): string
    {
        if ($reserved === null || $reserved === '') {
            return $this->inbound->generate();
        }

        $taken = HelpCenterInbox::query()
            ->withoutGlobalScopes()
            ->where('inbound_id', $reserved)
            ->exists();

        return $taken ? $this->inbound->generate() : $reserved;
    }

    /**
     * Step 2 (P2 §8).
     *
     * Anyone already in the workspace is linked straight to their user. Anyone who is not gets a
     * row with `user_id` null, and their email is returned so the caller can invite them once
     * the transaction has committed.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, HelpCenterSpaceMember> the rows still waiting on an invitation
     */
    private function createMembers(Workspace $workspace, User $actor, HelpCenterSpace $space, array $data): array
    {
        $pending = [];
        $seen = [];

        foreach ((array) ($data['members'] ?? []) as $row) {
            $email = mb_strtolower(trim((string) ($row['email'] ?? '')));

            if ($email === '' || isset($seen[$email])) {
                continue;
            }

            $seen[$email] = true;

            // Only groups the Space actually defines (P2 §6) — a group renamed in Step 1 after
            // being assigned should not survive as a label nothing points at.
            $groups = array_values(array_intersect(
                HelpCenterSpace::normalizeTypes((array) ($row['department_groups'] ?? [])),
                $space->groupList(),
            ));

            $user = $this->workspaceUser($workspace, $email);

            $member = HelpCenterSpaceMember::create([
                'tenant_id' => $workspace->id,
                'help_center_space_id' => $space->id,
                'user_id' => $user?->id,
                'email' => $email,
                // Only meaningful for somebody who has to be invited; an existing member
                // already has a workspace role and Step 2 does not change it.
                'invited_role' => $user === null ? $this->inviteRole($workspace, $actor, $row['role'] ?? null) : null,
                'department_groups' => $groups,
                'created_by' => $actor->id,
            ]);

            if ($user === null) {
                $pending[] = $member;
            }
        }

        return $pending;
    }

    /**
     * Invite the people who are not workspace members yet (P2 §8).
     *
     * Through WorkspaceInviter, not around it: "use the existing workspace invitation process"
     * is the requirement, and a second way to invite somebody would be a second set of seat
     * checks, expiry rules and emails to keep in step.
     *
     * @param  array<int, HelpCenterSpaceMember>  $pending
     */
    private function invite(Workspace $workspace, User $actor, array $pending): void
    {
        if ($pending === []) {
            return;
        }

        /*
         * Each with the role Step 2 asked for (P2 §8, HC-D19), already validated against the
         * actor's own authority by inviteRole().
         */
        $this->inviter->invite(
            $workspace,
            $actor,
            array_map(
                fn (HelpCenterSpaceMember $m) => ['email' => $m->email, 'role' => $m->invited_role],
                $pending,
            ),
        );

        /*
         * Link each member row to the invitation that was just created for it.
         *
         * Looked up by email afterwards rather than returned by the inviter: `invite()` reports
         * per-recipient STATUSES, and an address it refused — no seats, already invited — simply
         * has no invitation to link. Those rows stay pending with a null invitation, which is
         * the truth about them.
         */
        $workspace->run(function () use ($pending) {
            foreach ($pending as $member) {
                $invitation = WorkspaceInvitation::query()
                    ->where('email', $member->email)
                    ->where('status', WorkspaceInvitation::STATUS_PENDING)
                    ->latest('id')
                    ->first();

                if ($invitation !== null) {
                    $member->forceFill(['workspace_invitation_id' => $invitation->id])->save();
                }
            }
        });
    }

    /**
     * The workspace role a coworker is invited with (P2 §8, HC-D19).
     *
     * Two rules, and the second is the one that matters:
     *
     * 1. It has to be a role the workspace actually offers — `config('workspace.invite_roles')`.
     * 2. **The actor has to be allowed to hand it out.** `WorkspacePolicy::assignRole()` already
     *    says only an owner may create another owner, and everybody else may only assign roles
     *    strictly below their own. Step 2 is a new door into inviting people, and a new door
     *    into an existing permission must not be a way around it — otherwise an admin who
     *    cannot promote somebody to admin on Settings → Members could do it here.
     *
     * Anything that fails either test falls back to `member`: the plain working role, no
     * administrative rights. Silently downgrading is the safe direction, and the request layer
     * rejects a bad role before it ever reaches this — this is the backstop, not the message.
     */
    private function inviteRole(Workspace $workspace, User $actor, mixed $role): string
    {
        $role = is_string($role) ? trim($role) : '';

        if (! in_array($role, (array) config('workspace.invite_roles'), true)) {
            return 'member';
        }

        return $actor->can('assignRole', [$workspace, $role]) ? $role : 'member';
    }

    /** The workspace member behind an email address, if there is one. */
    private function workspaceUser(Workspace $workspace, string $email): ?User
    {
        return User::query()
            ->where('email', $email)
            ->whereIn('id', WorkspaceMembership::query()
                ->where('workspace_id', $workspace->id)
                ->where('status', WorkspaceMembership::STATUS_ACTIVE)
                ->select('user_id'))
            ->first();
    }

    /**
     * The metadata toggles, filtered to what config actually offers (P2 §18).
     *
     * An unavailable toggle is forced off however it arrived — that is HC-D18: AI Tag is
     * "Coming Soon", and a client that posts `ai_tag => true` is refused here rather than
     * merely discouraged by a disabled control.
     *
     * @param  array<string, mixed>  $submitted
     * @return array<string, bool>
     */
    private function metadata(array $submitted): array
    {
        $out = [];

        foreach ((array) config('help-center.metadata') as $key => $meta) {
            $out[$key] = ($meta['available'] ?? false)
                ? (bool) ($submitted[$key] ?? $meta['default'] ?? false)
                : false;
        }

        return $out;
    }

    /** A valid hex, or the first configured colour. */
    private function color(?string $value): string
    {
        $value = strtolower(trim((string) $value));

        if (preg_match('/^#[0-9a-f]{6}$/', $value)) {
            return $value;
        }

        return (string) (config('help-center.status_colors')[0] ?? '#6b7280');
    }

    /** @param  mixed  $values */
    /** @return array<int, int> */
    private function memberIds(mixed $values): array
    {
        return array_values(array_unique(array_map('intval', array_filter(
            (array) $values,
            fn ($v) => is_numeric($v),
        ))));
    }

    private function nullIfBlank(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
