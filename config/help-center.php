<?php

/*
|--------------------------------------------------------------------------
| Help Center (docs/features/help-center.md)
|--------------------------------------------------------------------------
| The module's fixed vocabulary: the kinds of Space a workspace may create, the domain its
| inbound addresses live on, the system views every Space carries, and the forwarding guides.
|
| Config rather than tables (HC-D9). None of this is workspace-editable — a table whose rows
| only ever come from a seeder is a migration pretending to be a feature, and a Space "type"
| stored as a label is a future reporting dimension that changes when somebody edits a word.
*/

return [

    /**
     * Where generated inbound addresses live (§8).
     *
     * Only the token is stored on the inbox; the address is composed with this (HC-D5), so
     * moving domains is an env change rather than a migration over every row.
     */
    'inbound_domain' => env('HELP_CENTER_INBOUND_DOMAIN', 'inbound.projectblock.app'),

    /**
     * The local part of a generated address: `<prefix>-<token>@<domain>` (§8).
     *
     * Configurable for the same reason the domain is — it is a deployment fact, and Postmark
     * Inbound routing on one server should not require editing a model on another.
     */
    'inbound_prefix' => env('HELP_CENTER_INBOUND_PREFIX', 'inbox'),

    /**
     * The shared secret Postmark's inbound webhook must present (P6).
     *
     * Postmark does not sign inbound webhooks, so a secret in the URL — either as the last path
     * segment or as the basic-auth password — is the only thing standing between this endpoint
     * and anyone who can guess it.
     *
     * EMPTY MEANS CLOSED. `PostmarkInboundController` refuses every request when this is unset,
     * because "not configured" must never mean "open": the alternative is an endpoint that
     * writes conversations into any workspace for anybody who finds the URL.
     */
    'inbound_secret' => env('HELP_CENTER_INBOUND_SECRET', ''),

    /**
     * How long an inbound test waits for its probe to come back, in seconds (P7).
     *
     * Two minutes: long enough for a provider's forwarding rule and Postmark to do their work,
     * short enough that somebody watching the card is not left guessing. Evaluated on read
     * rather than by a scheduled job, so a test cannot hang forever when the queue is down.
     */
    'inbound_test_timeout' => (int) env('HELP_CENTER_INBOUND_TEST_TIMEOUT', 120),

    /**
     * SUGGESTIONS for the Space Type field — explicitly not a dropdown (§3, HC-D9).
     *
     * Space Type is a free-text multi-value field: a workspace types whatever it calls its own
     * support, and a Space may be several at once. These are rendered by `pb-tags` as additive
     * "+ Customer Support" chips beside the input, so they save typing without constraining
     * what may be typed — and nothing validates against this list.
     *
     * A value removed from here tomorrow does not invalidate a Space already tagged with it,
     * which is the whole difference between a suggestion and an enum.
     */
    'space_type_suggestions' => [
        'Customer Support',
        'Technical Support',
        'Billing',
        'Sales',
        'Internal Support',
        'Partner Support',
    ],

    /** The most types one Space may carry, and the longest any one of them may be. */
    'space_type_max' => 8,

    'space_type_max_length' => 40,

    /**
     * The sections every Space carries in the navigation (P4).
     *
     * These replaced the six conversation views as the Space's sub-navigation. The views did
     * not disappear — they are FILTERS on the Space's Conversations screen, which is where a
     * filter belongs; a Space's nav is for the parts of a Space, and "Unassigned" is not one of
     * its parts.
     *
     * Ordered, and the first is where a Space opens.
     */
    'space_sections' => [
        'overview' => ['label' => 'Overview', 'icon' => 'house'],
        'conversations' => ['label' => 'Conversations', 'icon' => 'inbox'],
        'inbox' => ['label' => 'Inbox', 'icon' => 'rectangles-pair'],
        'workflow' => ['label' => 'Workflow', 'icon' => 'diagram-subtask'],
        'members' => ['label' => 'Members', 'icon' => 'users'],
        'settings' => ['label' => 'Settings', 'icon' => 'gear'],
    ],

    /**
     * The system views every Space carries (§16).
     *
     * Ordered, and the labels are exactly these: §16 asks specifically for "Mine" rather than
     * "Min" and "Drafts" rather than "Draft". Stated once so the sidebar, the routes and the
     * screen headings cannot drift apart.
     *
     * Phase 1 builds these as navigation with empty states (HC-D8) — they are the shape the
     * conversation work will fill.
     */
    'space_views' => [
        'unassigned' => ['label' => 'Unassigned', 'icon' => 'inbox',
            'empty' => 'Conversations with no assigned team member will appear here.'],
        'mine' => ['label' => 'Mine', 'icon' => 'user',
            'empty' => 'Conversations assigned to you will appear here.'],
        'drafts' => ['label' => 'Drafts', 'icon' => 'file-lines',
            'empty' => 'Replies you have saved but not sent will appear here.'],
        'assigned' => ['label' => 'Assigned', 'icon' => 'users',
            'empty' => 'Conversations assigned to any team member will appear here.'],
        'closed' => ['label' => 'Closed', 'icon' => 'check',
            'empty' => 'Resolved and closed conversations will appear here.'],
        'spam' => ['label' => 'Spam', 'icon' => 'circle-slash',
            'empty' => 'Conversations marked as spam will appear here.'],
    ],

    /**
     * The statuses an email address can hold (§11).
     *
     * `verified` is deliberately unreachable in this phase — nothing receives inbound mail yet,
     * so nothing can honestly set it (HC-D7). It is defined here because the column stores it
     * and the table renders it, and a status the UI cannot name is a status the UI will get
     * wrong when it becomes reachable.
     *
     * `tone` names a `._moretogether-badge--*` modifier that already exists in
     * public/assets/css/styles.css, rather than inventing a parallel vocabulary the stylesheet
     * would then have to grow to match.
     */
    'address_statuses' => [
        'pending' => ['label' => 'Setup Required', 'tone' => 'off'],
        'waiting' => ['label' => 'Waiting for Email', 'tone' => 'wait'],
        'verified' => ['label' => 'Verified', 'tone' => 'ok'],
        'error' => ['label' => 'Configuration Error', 'tone' => 'error'],
    ],

    /**
     * Step 3's expandable forwarding guides (§10).
     *
     * The point of the whole inbound design is that the customer-facing address never changes:
     * these explain how to make an EXISTING support mailbox forward to the generated one.
     */
    'providers' => [
        [
            'key' => 'google',
            'label' => 'Google Workspace / Gmail',
            'steps' => [
                'Sign in to the mailbox that receives your support email.',
                'Open Settings → See all settings → Forwarding and POP/IMAP.',
                'Click "Add a forwarding address" and paste your inbound address.',
                'Google sends a confirmation email to that address — we accept it automatically.',
                'Choose "Forward a copy of incoming mail to" and save.',
            ],
        ],
        [
            'key' => 'microsoft',
            'label' => 'Microsoft 365 / Outlook',
            'steps' => [
                'Sign in to Outlook on the web as the support mailbox.',
                'Open Settings → Mail → Forwarding.',
                'Tick "Enable forwarding" and paste your inbound address.',
                'Tick "Keep a copy of forwarded messages" if you want the mailbox to retain them.',
                'Save.',
            ],
        ],
        [
            'key' => 'zoho',
            'label' => 'Zoho Mail',
            'steps' => [
                'Sign in to Zoho Mail as the support account.',
                'Open Settings → Mail Accounts and choose the address.',
                'Under Email Forwarding, add your inbound address.',
                'Confirm the verification email Zoho sends to it.',
            ],
        ],
        [
            'key' => 'fastmail',
            'label' => 'Fastmail',
            'steps' => [
                'Sign in to Fastmail as the support user.',
                'Open Settings → Rules (or Forwarding).',
                'Add a rule that forwards all mail to your inbound address.',
                'Save the rule.',
            ],
        ],
        [
            'key' => 'other',
            'label' => 'Other / Custom Email Provider',
            'steps' => [
                'Find the forwarding or redirect setting in your mail provider.',
                'Forward all incoming mail for your support address to your inbound address.',
                'If the provider asks you to confirm ownership, it will send a verification email — that arrives here.',
                'Leave your public support address exactly as it is; customers keep writing to it.',
            ],
        ],
    ],

    /**
     * Step 5's metadata toggles (P2 §18).
     *
     * A map so the seven are declared once and an eighth is a config entry rather than a
     * migration, a model change and three template edits.
     *
     * `available => false` is AI Tag's "Coming Soon" (P2 §18). It is refused server-side, not
     * merely disabled in the UI (HC-D18) — "coming soon" is a statement about the feature, not
     * about the button.
     */
    'metadata' => [
        'channel' => ['label' => 'Channel', 'default' => true, 'available' => true,
            'help' => 'Show which channel a conversation arrived through.'],
        'snooze' => ['label' => 'Snooze', 'default' => true, 'available' => true,
            'help' => 'Let agents snooze a conversation until later.'],
        'rating' => ['label' => 'Rating', 'default' => false, 'available' => true,
            'help' => 'Collect a satisfaction rating when a conversation closes.'],
        'tag' => ['label' => 'Tag', 'default' => true, 'available' => true,
            'help' => 'Let agents tag conversations.'],
        'ai_tag' => ['label' => 'AI Tag', 'default' => false, 'available' => false,
            'help' => 'Tag conversations automatically.'],
        'company' => ['label' => 'Company', 'default' => false, 'available' => true,
            'help' => 'Show the company a customer belongs to.'],
        'customer_email' => ['label' => 'Customer Email Address', 'default' => true, 'available' => true,
            'help' => "Show the customer's email address on the conversation."],
    ],

    /** Where a conversation goes when its agent has been away too long (P2 §23). */
    'reassign_destinations' => [
        'unassigned' => 'Move to Unassigned',
        'available_agent' => 'Assign to an available agent',
    ],

    /** Colours offered by the workflow status picker (P2 §14). Any hex is still accepted. */
    'status_colors' => [
        '#22c55e', '#3b82f6', '#f59e0b', '#ef4444', '#8b5cf6',
        '#ec4899', '#14b8a6', '#6b7280',
    ],

    /** The most custom statuses one Space's workflow may hold, and the longest a name may be. */
    'status_max' => 20,

    'status_max_length' => 60,

    /** The most Department Groups one Space may define (P2 §6). */
    'department_group_max' => 20,

];
