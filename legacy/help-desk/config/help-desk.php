<?php

/*
|--------------------------------------------------------------------------
| Help Desk (docs/features/help-desk.md — Phase 1)
|--------------------------------------------------------------------------
| The five Help Desk roles and what each of them may do, plus the name the first inbox is
| provisioned with.
|
| Roles live HERE rather than in a `help_desk_roles` table (decision H2): they are five
| constants the product defines, not something a workspace composes. A table would add a join
| to every permission check and a seeding step to every workspace, to store values that only
| change when this file does.
|
| A role's abilities are the whole of what it may do INSIDE the Help Desk. They are deliberately
| independent of workspace and project roles — §5's rule is that the four are evaluated
| separately and the most restrictive applicable permission wins, so a workspace admin who is
| not a Help Desk member has no Help Desk role at all.
*/

return [

    /**
     * What the first inbox is called when a Help Desk is provisioned.
     *
     * Every Help Desk gets one, because inbox-level access (FR-1.7) is only meaningful if there
     * is an inbox to grant. Phase 2 adds the email channel and routing behind it.
     */
    'default_inbox' => 'Support',

    /**
     * What the first SPACE is called when a Help Desk is provisioned
     * (Workspace & Inbox Assignment requirements §7, §16).
     *
     * The hierarchy is Help Desk → space → inbox, so a Help Desk starts with one of each. An
     * organization running a single support operation never has to think about spaces; one
     * running three creates the other two when it needs them.
     */
    'default_space' => 'Customer Support',

    /**
     * Inbound email (Phase 2, FR-2.4).
     *
     * Mail reaches the application through a signed webhook (decision H19): the provider POSTs a
     * normalized message to `/help-desk/email/inbound` with an HMAC of the raw body in a header.
     * Provider-agnostic on purpose — Postmark, SendGrid, Mailgun and an SMTP relay of your own
     * all map onto the same payload, and none of them get to decide the application's shape.
     *
     * `secret` UNSET means the endpoint is switched off and refuses everything. That is the
     * right default: an ingestion endpoint that accepts unsigned requests is an endpoint that
     * lets anybody file a support conversation as anybody.
     */
    'inbound' => [
        /**
         * The domain every generated inbound address sits on.
         *
         * Inbox addresses are GENERATED, not typed (Inbound Email requirements §2): a customer
         * forwards their real support address to one of these, and Postmark delivers it here.
         * The domain is config because it is deployment-specific — the address itself is not
         * something anybody gets to choose, which is the whole point of generating it.
         */
        'domain' => env('HELP_DESK_INBOUND_DOMAIN', 'inbound.projectblock.app'),

        /**
         * Postmark's inbound webhook token (Phase 1 of the inbound requirements, §3).
         *
         * Postmark does NOT sign inbound webhooks — the documented practice is a secret in the
         * URL or basic auth — so its endpoint carries this token in the path and compares it in
         * constant time. Unset means the Postmark endpoint refuses everything, like the generic
         * one does without a secret.
         */
        'postmark_token' => env('HELP_DESK_POSTMARK_TOKEN'),

        /**
         * How long a "Verify Connection" attempt waits before it is called an error (§8).
         *
         * Computed on read rather than swept by a scheduled job — the same approach
         * `WorkspaceInvitation::markExpiredIfLapsed()` takes, and for the same reason: a status
         * that is only correct while a worker is running is a status you cannot trust.
         */
        'verification_window_hours' => 24,

        'secret' => env('HELP_DESK_INBOUND_SECRET'),

        // The header carrying `hash_hmac('sha256', $rawBody, $secret)`.
        'signature_header' => env('HELP_DESK_INBOUND_SIGNATURE_HEADER', 'X-PB-Signature'),

        // Which queue the ingestion job runs on (CLAUDE.md §11). A webhook must return fast:
        // a provider that times out retries, and retrying is how duplicates are made.
        'queue' => env('HELP_DESK_INBOUND_QUEUE', 'default'),
    ],

    /**
     * Outbound delivery events (Phase 2, FR-2.9).
     *
     * The same provider, reporting back what happened to mail we sent: delivered, bounced,
     * complained, deferred, failed. It posts to `/help-desk/email/delivery`, signed the same way
     * as inbound — one secret, one verification path, because two would be one more thing to
     * get wrong on the endpoint that has no session behind it.
     *
     * `failure_statuses` decides which of those events makes a conversation visibly broken and
     * notifies somebody. A deferral is not a failure: mail servers defer constantly and it
     * usually resolves itself, so treating it as one would train people to ignore the flag.
     */
    'delivery' => [
        'failure_statuses' => ['bounced', 'failed', 'complained'],
    ],

    /**
     * Attachments on arriving mail (inbound requirements §11.6).
     *
     * Limits exist because the sender chooses the file, not us: an inbound endpoint with no
     * ceiling is a disk-filling service anybody with the address can use. Files over the
     * per-file limit are recorded WITHOUT their contents rather than dropped silently — an
     * agent needs to know the customer sent something, even when we did not keep it.
     */
    'attachments' => [
        'max_file_bytes' => 10 * 1024 * 1024,   // 10 MB per file
        'max_total_bytes' => 25 * 1024 * 1024,  // 25 MB per message
    ],

    /**
     * The five roles, highest authority first.
     *
     * `rank` is comparable in the same way `WorkspaceMembership::RANKS` is: "you may not act on
     * somebody who outranks you" and "you may not hand out a role above your own" are then one
     * comparison rather than a table of special cases.
     *
     * `all_inboxes` answers FR-1.7. Admins and Managers work across the whole Help Desk, so
     * naming individual inboxes for them would be a list that has to be maintained and can only
     * ever be wrong. Everybody else sees exactly the inboxes they have been given.
     *
     * `abilities` are the permissions Phase 1 can enforce today, plus the two — `reply` and
     * `note` — that define what Agent, Collaborator and Viewer MEAN. Those two are what §5's
     * "Collaborator: internal-only participation; cannot send customer replies" is, and the
     * conversation phases read them rather than re-deciding the role model.
     */
    'roles' => [
        'admin' => [
            'label' => 'Admin',
            'description' => 'Full Help Desk configuration and operational control.',
            'rank' => 50,
            'all_inboxes' => true,
            'abilities' => ['view', 'reply', 'note', 'assign', 'move', 'manage_inboxes', 'manage_members', 'manage_settings'],
        ],
        'manager' => [
            'label' => 'Manager',
            'description' => 'Team operations, workload, assignment and reports.',
            'rank' => 40,
            'all_inboxes' => true,
            'abilities' => ['view', 'reply', 'note', 'assign', 'move'],
        ],
        'agent' => [
            'label' => 'Agent',
            'description' => 'Handles customer conversations in the inboxes they are given.',
            'rank' => 30,
            'all_inboxes' => false,
            // `move` is here because Phase 2's acceptance criterion says so in as many words:
            // "agents can move a conversation between authorized inboxes". Misrouted mail is
            // something the person reading it fixes, not something they escalate.
            'abilities' => ['view', 'reply', 'note', 'move'],
        ],
        'collaborator' => [
            'label' => 'Collaborator',
            'description' => 'Internal-only participation — can add notes, never a customer reply.',
            'rank' => 20,
            'all_inboxes' => false,
            'abilities' => ['view', 'note'],
        ],
        'viewer' => [
            'label' => 'Viewer',
            'description' => 'Read-only access to the inboxes they are given.',
            'rank' => 10,
            'all_inboxes' => false,
            'abilities' => ['view'],
        ],
    ],
];
