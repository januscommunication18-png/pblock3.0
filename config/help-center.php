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
        /*
         * "Inbox" is where the CONVERSATIONS are — the working screen an agent lives on.
         *
         * It was called Conversations, with a separate Inbox section holding the inbound
         * address and connected addresses. Two entries, and the one named after the thing you
         * work in led to configuration. The configuration moved under Settings, where the rest
         * of the Space's setup already lives, and this name now matches what an agent opens it
         * for.
         */
        'inbox' => ['label' => 'Inbox', 'icon' => 'inbox'],
        /*
         * Workflow is NOT here any more (P15). It moved into Settings, beside the Inbox page.
         *
         * It was a tab next to Inbox and Members — screens an agent works in all day — while
         * being a read-only description of how the Space is configured. Every other answer to
         * "how is this Space set up?" is behind Settings, and having one of them somewhere else
         * meant the tab bar mixed two different kinds of thing.
         *
         * /spaces/{id}/workflow still resolves; see routes/help-center.php for the redirect.
         */
        /*
         * Members is NOT here any more. It moved into Settings, beside Inbox and Workflow.
         *
         * It sat in the tab bar next to Inbox — the screen an agent works in all day — while
         * being administration: who may work this Space, what Department Groups they cover, and
         * an invite that reaches somebody who has never opened ProjectBlock. Every other answer
         * to "how is this Space set up?" is behind Settings, and one of them living in the
         * working tabs meant the bar mixed two different kinds of thing.
         *
         * /spaces/{id}/members still resolves; see routes/help-center.php for the redirect.
         */
        'settings' => ['label' => 'Settings', 'icon' => 'gear'],
    ],

    /**
     * A Request's priority (P9).
     *
     * Config, and NOT a per-Space workflow concept — unlike status, which is deliberately the
     * Space's own. Priority means the same thing in every Space ("how urgent is this"), whereas
     * a status is a step in one team's process; making both configurable would invite a Space to
     * define "Urgent" as something another Space's reports could not add up.
     */
    'priorities' => [
        'urgent' => ['label' => 'Urgent', 'color' => '#ef4444'],
        'high' => ['label' => 'High', 'color' => '#f59e0b'],
        /*
         * The VALUE stays `normal`; only the label changed (P28).
         *
         * The requirement's vocabulary is Urgent / High / Medium / Low / No Priority. Renaming
         * the stored value would have meant a data migration over every Request for a word on a
         * chip — the column keeps what it has always kept, and the screen reads "Medium".
         */
        'normal' => ['label' => 'Medium', 'color' => '#6b7280'],
        'low' => ['label' => 'Low', 'color' => '#9ca3af'],
        /*
         * "No Priority" as a VALUE rather than as NULL.
         *
         * `priority` is `string(20) NOT NULL DEFAULT 'normal'`, and making it nullable to express
         * "none" would be a schema change plus a null branch in every reader — for a state that
         * is simply another word in a fixed list. Stored as `none`, it sorts, filters and groups
         * like the rest of them.
         */
        'none' => ['label' => 'No Priority', 'color' => '#d1d5db'],
    ],

    /**
     * The Request views — the Help Center's TOP-LEVEL navigation (§16, P21).
     *
     * These were the six chips on a Space's Inbox screen. They are now entries in the Help
     * Center's own navigation, sitting between Overview and Spaces, and each one is a URL:
     * `/help-center/inbox/{view}`. The reason is what they actually are — an agent's working
     * queues. "What is assigned to me" is the question somebody opens the Help Center to answer,
     * and it was two clicks and a Space deep, answerable only one Space at a time.
     *
     * They span EVERY active Space, which is the other half of the move: an agent working three
     * Spaces had three Inboxes to check and no screen that added them up.
     *
     * `nav` is whether the view appears in that navigation. Spam does not (P21) — it is where
     * things go to be ignored, and a permanent nav entry for it invites reading. It stays a
     * filter on the Inbox screen, which is where you look when you suspect something was
     * misfiled.
     *
     * `counted` is whether a number is drawn beside it. Drafts and Closed carry none: a draft
     * count is a number nothing can produce yet, and "Closed" is an archive rather than a
     * workload — a number there is a growing figure nobody is being asked to act on.
     *
     * Ordered, and the order is the navigation's.
     */
    'request_views' => [
        'unassigned' => ['label' => 'Unassigned', 'icon' => 'inbox', 'nav' => true, 'counted' => true,
            'empty' => 'Requests with no assigned team member will appear here.'],
        'mine' => ['label' => 'Mine', 'icon' => 'user', 'nav' => true, 'counted' => true,
            'empty' => 'Requests assigned to you will appear here.'],
        /*
         * "Draft", singular, and this is the one label that changed in the move.
         *
         * §16 asked for "Drafts" when these were chips on one Space's Inbox. P21 specifies the
         * navigation reads "Draft"; it is the same view and the same key, so nothing but the
         * word on screen is different.
         */
        'drafts' => ['label' => 'Draft', 'icon' => 'file-lines', 'nav' => true, 'counted' => false,
            'empty' => 'Replies you have saved but not sent will appear here.'],
        'assigned' => ['label' => 'Assigned', 'icon' => 'users', 'nav' => true, 'counted' => true,
            'empty' => 'Requests assigned to any team member will appear here.'],
        'closed' => ['label' => 'Closed', 'icon' => 'check', 'nav' => true, 'counted' => false,
            'empty' => 'Resolved and closed Requests will appear here.'],
        /*
         * Snoozed (P45) — in the navigation, and COUNTED.
         *
         * Counted because the number answers a question an agent actually has: "how much work is
         * about to land back on me?" A snoozed queue with no number is a queue nobody looks at
         * until it surprises them.
         */
        'snoozed' => ['label' => 'Snoozed', 'icon' => 'clock', 'nav' => true, 'counted' => true,
            'empty' => 'Requests you have snoozed will reappear here until they are due.'],
        /*
         * Spam — in the navigation, immediately after Snoozed (P47).
         *
         * P21 deliberately kept it OUT: "it is where things go to be ignored; a permanent nav
         * entry for it invites reading." The requirement asks for it in the bar, which is the
         * product owner's call to make, so it is there.
         *
         * Still NOT counted, and that half of P21's reasoning stands: a badge on Spam is an
         * unread count for mail nobody should be reading. The entry gets you to the list when
         * you suspect something was misfiled; it should not nag.
         */
        'spam' => ['label' => 'Spam', 'icon' => 'circle-slash', 'nav' => true, 'counted' => false,
            'empty' => 'Requests marked as spam will appear here.'],
    ],

    /*
     * What a snooze waits for (P45).
     *
     * Two conditions, because they are the two answers to one question — does the customer
     * writing back cut the snooze short? Everything else a product might offer here ("if no
     * reply from anyone", "if not reassigned") is a workflow rule, not a snooze.
     */
    /*
     * Outgoing email, per Space (docs/features/help-center.md, P48).
     *
     * These are the DEFAULTS, not the stored values. A Space with no row in
     * `help_center_email_templates` uses what is here, which is what makes a brand-new Space send
     * sensible mail with nothing configured — and what makes "Restore Default" a delete rather
     * than a copy of this text into every Space that ever wanted it back.
     *
     * The bodies are the same HTML the editor produces, so what an administrator opens is what
     * was actually going to be sent rather than a rendering of something else.
     */
    /*
     * The System Categories (docs/features/help-center.md, P53).
     *
     * FIXED, and fixed in config rather than in a table on purpose: the requirement is that
     * ProjectBlock controls them and that they "cannot be renamed, deleted, or customized". A
     * table would be a thing with an id, a tenant and an update endpoint — which is to say a
     * thing somebody would eventually be able to rename. Config is the honest expression of
     * "this is ours, not yours".
     *
     * They are a VOCABULARY, not a workflow. A Space's workflow is its own — see the migration
     * note on help_center_statuses — and these five are the shared meanings a status can carry
     * so that reporting and automation have something stable to reason about across Spaces that
     * name their states differently.
     */
    /*
     * The visual rating scales (docs/features/help-center.md, P56).
     *
     * `points` is how many choices the customer sees. `normalise` maps each choice onto the 1–5
     * score every report totals — the requirement's "regardless of the visual rating type, the
     * system must store a normalized score".
     *
     * Thumbs is the interesting one: two choices onto 1 and 5, because a thumbs-down is not a
     * "2 out of 2", it is as negative as the scale goes. Averaging it as 2 would make a Space
     * that uses thumbs look permanently mediocre next to one that uses stars.
     */
    'rating_types' => [
        'stars5' => ['label' => '5 Stars', 'points' => 5, 'glyph' => 'star',
            'normalise' => [1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 5]],
        'emoji5' => ['label' => '5 Emoji', 'points' => 5, 'glyph' => 'emoji',
            'normalise' => [1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 5]],
        'thumbs' => ['label' => 'Thumbs Up / Down', 'points' => 2, 'glyph' => 'thumb',
            'normalise' => [1 => 1, 2 => 5]],
        'scale10' => ['label' => '1–10 Rating', 'points' => 10, 'glyph' => 'number',
            // Two points of the ten per point of the five, rounded up: 1–2 -> 1, 3–4 -> 2 …
            'normalise' => [1 => 1, 2 => 1, 3 => 2, 4 => 2, 5 => 3, 6 => 3, 7 => 4, 8 => 4, 9 => 5, 10 => 5]],
    ],

    /*
     * The five default labels. A Space may rename them; renaming must not move the score, which
     * is why the score is the KEY and the label is only ever the value.
     */
    'rating_labels' => [
        1 => 'Very Dissatisfied',
        2 => 'Dissatisfied',
        3 => 'Neutral',
        4 => 'Satisfied',
        5 => 'Very Satisfied',
    ],

    /** When a rating request is created. */
    'rating_triggers' => [
        'resolved' => 'Ticket moves to Resolved',
        'closed' => 'Ticket moves to Closed',
        'status' => 'A specific workflow status',
        'manual' => 'Only when an agent asks',
    ],

    /** How long after the trigger it is sent. Minutes, so "custom" is the same field. */
    'rating_delays' => [
        0 => 'Immediately',
        60 => 'After 1 hour',
        360 => 'After 6 hours',
        1440 => 'After 24 hours',
    ],

    'rating_comment_requirements' => [
        'optional' => 'Optional',
        'always' => 'Required for all ratings',
        'low_only' => 'Required for low ratings only',
    ],

    'system_categories' => [
        'open' => [
            'label' => 'Open',
            'color' => '#22c55e',
            'description' => 'A new request that nobody has picked up yet.',
        ],
        'active' => [
            'label' => 'Active',
            'color' => '#3b82f6',
            'description' => 'Somebody is working on it right now.',
        ],
        'waiting' => [
            'label' => 'Waiting',
            'color' => '#f59e0b',
            'description' => 'Progress is blocked on a reply — usually the customer\'s.',
        ],
        'resolved' => [
            'label' => 'Resolved',
            'color' => '#8b5cf6',
            'description' => 'An answer has been given, but the request is not shut yet.',
        ],
        'closed' => [
            'label' => 'Closed',
            'color' => '#6b7280',
            'description' => 'Finished. The clock stops and it leaves the working queues.',
        ],
    ],

    'email_templates' => [
        'auto_response' => [
            'label' => 'Customer Auto-Response',
            'description' => 'Sent to the customer when their email opens a new ticket.',
            'name' => 'New Ticket Auto Response',
            'subject' => 'We received your request — Ticket {{ticket_number}}',
            // `can_disable`: the requirement is explicit that the auto-response may be turned off
            // and that the agent reply template may not — "should remain available whenever an
            // agent replies". A flag rather than a special case in four places.
            'can_disable' => true,
            'body' => '<p>Hi {{customer_name}},</p>'
                .'<p>Thanks for getting in touch. We have your request and a member of the team '
                .'will come back to you.</p>'
                .'<p>Your ticket reference is <strong>{{ticket_number}}</strong>, about '
                .'&ldquo;{{ticket_subject}}&rdquo;.</p>'
                .'<p>You can reply to this email at any time and it will reach the same ticket.</p>'
                .'<p>{{space_name}}</p>',
        ],

        'agent_reply' => [
            'label' => 'Agent Reply',
            'description' => 'The wrapper around every reply an agent sends from a ticket.',
            'name' => 'Agent Reply',
            'subject' => 'Re: {{ticket_subject}} [{{ticket_number}}]',
            'can_disable' => false,
            /*
             * `{{reply_content}}` is the whole point of this one.
             *
             * A template without it would send the customer a wrapper and none of the answer, so
             * the save endpoint refuses one — see EmailTemplateController.
             */
            'body' => '<p>Hi {{customer_name}},</p>'
                .'{{reply_content}}'
                .'{{agent_signature}}'
                .'<p>Ticket: {{ticket_number}}</p>',
        ],

        /*
         * The rating request (P56 §8).
         *
         * A template type like the others, so a Space edits it on the same page with the same
         * preview and the same Restore Default — the requirement asks it to use the existing
         * Email Templates functionality, and this is what that means concretely.
         */
        'rating_request' => [
            'label' => 'Rating Request',
            'description' => 'Asks the customer how their support experience went.',
            'name' => 'Rating Request',
            'subject' => 'How did we do? — Ticket {{ticket_number}}',
            /*
             * Disable-able (P60).
             *
             * It shipped as `false` alongside the agent reply, which was wrong by analogy: the
             * agent reply must always be available because an agent is mid-sentence when they
             * need it. Nobody is mid-anything when a rating request goes out, and the
             * requirement asks for the switch — "if disabled, the system should not send the
             * rating request email".
             */
            'can_disable' => true,
            'body' => '<p>Hi {{customer_name}},</p>'
                .'<p>Your request &ldquo;{{ticket_subject}}&rdquo; ({{ticket_number}}) has been '
                .'wrapped up. Would you take a moment to tell us how it went?</p>'
                .'<p><a href="{{rating_link}}" style="display:inline-block;padding:10px 18px;'
                .'background:#2563eb;color:#ffffff;border-radius:6px;text-decoration:none;'
                .'font-weight:600;">Rate your experience</a></p>'
                .'<p>{{space_name}}</p>',
        ],

        'ticket_layout' => [
            'label' => 'Ticket Email Layout',
            'description' => 'The header and footer wrapped around ticket emails sent from this Space.',
            'name' => 'Ticket Email Layout',
            // No subject: a layout is not a message, it is what a message is put inside.
            'subject' => null,
            'can_disable' => true,
            /*
             * `{{email_content}}` is where the rendered template above lands.
             *
             * The layout is the outer of two, so its own variable is not one of the ticket's —
             * it is the slot. Without it the layout would replace the email rather than frame it,
             * so the save endpoint refuses one of those too.
             */
            'body' => '<p style="font-size:13px;color:#6b7280;margin:0 0 16px;">{{space_name}}</p>'
                .'{{email_content}}'
                .'<p style="border-top:1px solid #e5e7eb;margin-top:24px;padding-top:12px;'
                .'font-size:12px;color:#9ca3af;">Reply to this email to continue the conversation '
                .'— please keep the subject line as it is so your message reaches the right ticket '
                .'({{ticket_number}}).</p>',
        ],
    ],

    /*
     * The merge tags the editor offers, and the ONLY ones substitution knows (P48).
     *
     * `html => true` marks a value that is already sanitized markup and must NOT be escaped on
     * the way in. Everything else is text and gets escaped — a customer called
     * `<script>` is a customer, not a script.
     */
    'email_variables' => [
        ['tag' => 'customer_name', 'label' => 'Customer Name'],
        ['tag' => 'customer_email', 'label' => 'Customer Email'],
        ['tag' => 'ticket_number', 'label' => 'Ticket Number'],
        ['tag' => 'ticket_subject', 'label' => 'Ticket Subject'],
        ['tag' => 'agent_name', 'label' => 'Agent Name'],
        ['tag' => 'agent_signature', 'label' => 'Agent Signature', 'html' => true],
        ['tag' => 'reply_content', 'label' => 'Agent Reply Content', 'html' => true, 'types' => ['agent_reply']],
        ['tag' => 'email_content', 'label' => 'Email Content', 'html' => true, 'types' => ['ticket_layout']],
        // The customer's rating link (P56). Only offered on the rating template — a link that
        // renders empty everywhere else is a link somebody will paste into the wrong email.
        ['tag' => 'rating_link', 'label' => 'Rating Link', 'types' => ['rating_request']],
        ['tag' => 'space_name', 'label' => 'Space Name'],
        ['tag' => 'support_email', 'label' => 'Support Email'],
    ],

    'snooze_conditions' => [
        'if_no_reply' => [
            'label' => 'If no reply',
            'help' => 'Comes back sooner if the customer replies.',
        ],
        'regardless' => [
            'label' => 'Regardless of reply',
            'help' => 'Stays snoozed until the time above, even if the customer replies.',
        ],
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
        /*
         * NO LONGER ROUTED. Settings → Channel became the channel list below (P12); this
         * conversation-display switch lost its page in that change.
         *
         * The key is kept so `defaultMetadata()` still writes it and no Space's stored map
         * silently loses a value it already holds. It needs a new home — with the other
         * conversation-display toggles — or an explicit removal; it should not stay a stored
         * flag with no way to reach it for long.
         */
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
        /*
         * The MASTER switch for Company & Customer (P75). Relabelled, not re-keyed: the stored
         * map on every existing Space already holds `company`, and renaming the key would have
         * meant a migration to keep those Spaces' answer.
         */
        'company' => ['label' => 'Company & Customer', 'default' => false, 'available' => true,
            'help' => 'Track the customers who write in and the companies they belong to.'],

        /*
         * The four sub-switches (P75 §2). They are only ever reachable from the Company &
         * Customer page, which draws them under the master switch and disables them while it is
         * off — the master is what decides whether the module exists for this Space at all.
         */
        'customer_management' => ['label' => 'Customer Management', 'default' => true, 'available' => true,
            'help' => 'Keep a record for each person who writes in.'],
        'company_management' => ['label' => 'Company Management', 'default' => true, 'available' => true,
            'help' => 'Group customers under the company they belong to.'],
        'customer_custom_fields' => ['label' => 'Customer Custom Fields', 'default' => false, 'available' => true,
            'help' => 'Record your own fields against a customer.'],
        'company_custom_fields' => ['label' => 'Company Custom Fields', 'default' => false, 'available' => true,
            'help' => 'Record your own fields against a company.'],
        'ticket_metadata_mapping' => ['label' => 'Ticket Metadata Mapping', 'default' => false, 'available' => true,
            'help' => 'Map fields on an incoming request onto customer and company records.'],
        'customer_email' => ['label' => 'Customer Email Address', 'default' => true, 'available' => true,
            'help' => "Show the customer's email address on the conversation."],
    ],

    /**
     * The Space Settings sub-navigation (P11).
     *
     * Settings stopped being one long read-only panel and became one routed page per section behind
     * their own left-hand nav, matching Project Settings. This list is the single source of
     * what those pages are: the nav renders from it, the router validates against it (an
     * unknown segment 404s rather than rendering an empty screen), and the controller decides
     * what to load and what to accept from it. The same shape as `projects.settings_nav`, for
     * the same reason — one list, three consumers that cannot disagree.
     *
     * `key` is the URL segment. `kind` is what the page RENDERS, and several keys share one:
     * six of these are a single metadata switch, so they are one panel parameterised by
     * `metadata` rather than six near-identical screens.
     *
     * Ordered, and the first is where Settings opens.
     */
    'space_settings_nav' => [
        // Not a stored setting — the Space's Inbox, its generated inbound address and the
        // customer-facing addresses connected to it. It leads because it is the thing people
        // open Settings to check (P8).
        ['key' => 'inbox', 'label' => 'Inbox', 'status' => 'active', 'kind' => 'inbox'],

        // The Space's status list (P15), moved here from the Space's own tab bar. Second,
        // because the two questions people open Settings with are "where does mail arrive?"
        // and "what states can a Request be in?".
        ['key' => 'workflow', 'label' => 'Workflow', 'status' => 'active', 'kind' => 'workflow'],

        /*
         * SLA (docs/features/helpdesk-sla.md, §2) — four pages on one screen: Policies,
         * Business Hours, Holiday Calendar and Escalation Rules.
         *
         * Directly after Workflow, because the two are one conversation: a status decides
         * whether the clock runs (§19), and a policy decides how long it may run for. Reading
         * one without the other tells a team half of what happens to a ticket.
         *
         * Its own `kind`, and its own script — see space-settings.blade.php. Four resources with
         * their own tables is more than the settings panel's one-endpoint shape can carry.
         */
        ['key' => 'sla', 'label' => 'SLA', 'status' => 'active', 'kind' => 'sla'],

        /*
         * Who works this Space — moved here from the Space's own tab bar.
         *
         * Third, after the two questions people open Settings with ("where does mail arrive?"
         * and "what states can a Request be in?"), and before the display switches: it is
         * administration of the Space, which is what the pages above it are, and unlike them it
         * writes through its own member routes rather than through the settings endpoint.
         *
         * Its own `kind`, because the page is a Tabulator grid rather than a settings panel —
         * see resources/views/help-center/space-settings.blade.php for where that is handled.
         */
        ['key' => 'members', 'label' => 'Members', 'status' => 'active', 'kind' => 'members'],

        // The channel list (P12) — which ways customers can reach this Space. Not a metadata
        // switch any more; see `metadata.channel` above for what it replaced.
        ['key' => 'channel', 'label' => 'Channel', 'status' => 'active', 'kind' => 'channels'],
        ['key' => 'snooze', 'label' => 'Snooze', 'status' => 'active', 'kind' => 'metadata', 'metadata' => 'snooze'],
        /*
         * Rating is its own KIND now (P56), not a metadata switch.
         *
         * It was one toggle; the requirement turns it into twenty settings, a customer-facing
         * page and a reporting feed. `metadata.rating` still exists and is still mirrored on save
         * — see RatingController — so anything that already reads it keeps working.
         */
        ['key' => 'rating', 'label' => 'Rating', 'status' => 'active', 'kind' => 'rating'],
        /*
         * Still the `tag` metadata switch — and ALSO the Space's tag vocabulary (P14).
         *
         * `manages` is an addition to the panel, not a change of `kind`: the switch is saved
         * through the one settings endpoint exactly as the other five are, and the tags are
         * created and deleted through their own routes because a tag is a row, not a field.
         * Making this a new `kind` would have meant a second copy of the toggle's save.
         */
        ['key' => 'tag', 'label' => 'Tag', 'status' => 'active', 'kind' => 'metadata',
            'metadata' => 'tag', 'manages' => 'tags'],

        /*
         * Coming Soon, and `status` is the ONLY thing that says so.
         *
         * The underlying `metadata.ai_tag` already carries `available => false`, which is what
         * refuses it server-side (HC-D18). This flag governs the nav item — unclickable, badged
         * — so the two facts stay in their own places: one is about the feature, one about the
         * link to it.
         */
        ['key' => 'ai-tag', 'label' => 'AI Tag', 'status' => 'soon', 'kind' => 'metadata', 'metadata' => 'ai_tag'],

        /*
         * Company & Customer (P75) — its own KIND now, not a metadata switch with a list.
         *
         * It was `kind => metadata, manages => company_fields`: one toggle and one list of
         * Company custom fields. The requirement turns it into five switches, two field lists
         * and a mapping table, which is more than `manages` can add to a panel built around a
         * single `enabled` boolean.
         *
         * The URL segment stays `company` (HC-D52) — a URL is a promise, and the label is the
         * only thing the rename changes.
         */
        ['key' => 'company', 'label' => 'Company & Customer', 'status' => 'active',
            'kind' => 'company_customer', 'metadata' => 'company'],
        ['key' => 'customer-email-address', 'label' => 'Customer Email Address', 'status' => 'active',
            'kind' => 'metadata', 'metadata' => 'customer_email'],

        /*
         * The Space's outgoing email (P48) — templates and signatures on one page.
         *
         * Placed after Channel, which is where mail COMES IN: the two questions are "how do
         * customers reach this Space?" and "what does this Space send back?", and they belong
         * next to each other.
         *
         * One nav entry rather than two, because the requirement puts Agent Signature underneath
         * Email Template ("Space → Settings → Email Template → Agent Signature") and because a
         * signature is only ever seen through a template — splitting them would mean editing the
         * thing and its content on two different screens.
         */
        ['key' => 'email-template', 'label' => 'Email Template', 'status' => 'active', 'kind' => 'email_templates'],

        ['key' => 'auto-bcc', 'label' => 'Auto BCC', 'status' => 'active', 'kind' => 'auto_bcc'],
        ['key' => 'reassignment', 'label' => 'Reassignment', 'status' => 'active', 'kind' => 'reassignment'],

        /*
         * The URL segment is `auto-flow-on-mention` because that is what the requirement
         * specifies and a URL is a promise; the LABEL says "Follow", which is what the setting
         * does and what `auto_follow_mentions` is called everywhere else in this codebase.
         */
        ['key' => 'auto-flow-on-mention', 'label' => 'Auto Follow on Mention', 'status' => 'active',
            'kind' => 'auto_follow'],
    ],

    /**
     * The field types a Company custom field may be (P18).
     *
     * `options` is the whole vocabulary this list carries: it says whether a type is a CHOICE
     * — one somebody has to author before the field means anything — or a free input that needs
     * no configuration. The modal shows its Options section from this flag, the request demands
     * at least one option from it, and the Company form will render from it. One list, so those
     * three cannot disagree about whether a Radio needs choices.
     *
     * `rows` belongs to the two text areas and to nothing else. It is the size the field is
     * DRAWN at, not a limit on what may be typed.
     */
    'company_field_types' => [
        ['value' => 'input', 'label' => 'Input', 'options' => false],
        ['value' => 'dropdown', 'label' => 'Dropdown', 'options' => true],
        ['value' => 'multi_select', 'label' => 'Multiple Select', 'options' => true],
        ['value' => 'time', 'label' => 'Time Picker', 'options' => false],
        ['value' => 'textarea_small', 'label' => 'Small Text Area', 'options' => false, 'rows' => 3],
        ['value' => 'textarea_long', 'label' => 'Long Text Area', 'options' => false, 'rows' => 8],
        ['value' => 'checkbox', 'label' => 'Checkbox', 'options' => true],
        ['value' => 'radio', 'label' => 'Radio', 'options' => true],
    ],

    /** The most options one choice field may carry, and the longest any one of them may be. */
    'company_field_option_max' => 50,

    'company_field_option_max_length' => 100,

    /**
     * Ticket Metadata Mapping (P75 §3–§4).
     *
     * Three vocabularies, declared once here because five things read them: the mapping form's
     * three dropdowns, the request that validates a mapping, the engine that applies one, and
     * the panel that explains what a stored mapping means. A source key that meant one thing to
     * the picker and another to the engine would be a mapping that silently wrote the wrong
     * column.
     *
     * SOURCES are what an incoming Request carries. `resolver` is the key `TicketMetadata`
     * produces — the parser's output map — and is deliberately the same string as `key`, so the
     * two lists cannot drift apart; it is named separately because it is the CONTRACT, and a
     * future source whose parsed name differs from its display key needs somewhere to say so.
     */
    'mapping_sources' => [
        ['key' => 'sender_name', 'label' => 'Sender Name'],
        ['key' => 'sender_email', 'label' => 'Sender Email'],
        ['key' => 'sender_phone', 'label' => 'Sender Phone'],
        ['key' => 'email_domain', 'label' => 'Email Domain'],
        ['key' => 'company_name', 'label' => 'Company Name'],
        ['key' => 'ticket_subject', 'label' => 'Ticket Subject'],
        ['key' => 'ticket_channel', 'label' => 'Ticket Channel'],
        ['key' => 'external_customer_id', 'label' => 'External Customer ID'],
        ['key' => 'external_company_id', 'label' => 'External Company ID'],
        /*
         * The escape hatch. An integration, an intake form or an API caller sends whatever its
         * own system calls a field, and this product cannot enumerate those — so one source
         * reads a NAMED key out of the inbound payload's own bag, and the name is stored on the
         * mapping row rather than in this list.
         */
        ['key' => 'custom', 'label' => 'Custom / Integration Field', 'named' => true],
    ],

    /**
     * The two record types a mapping may write to, and the attributes each one offers.
     *
     * `custom_field` is in both lists and is the one destination that needs a second answer —
     * WHICH field — which is why the mapping row carries a nullable `custom_field_id` and the
     * request demands it for exactly this value.
     */
    'mapping_destinations' => [
        'customer' => [
            'label' => 'Customer',
            'fields' => [
                ['key' => 'name', 'label' => 'Name'],
                ['key' => 'email', 'label' => 'Email'],
                ['key' => 'phone', 'label' => 'Phone'],
                ['key' => 'company', 'label' => 'Company (text)'],
                ['key' => 'external_id', 'label' => 'External Customer ID'],
                ['key' => 'custom_field', 'label' => 'Custom Field', 'custom' => true],
            ],
        ],
        'company' => [
            'label' => 'Company',
            'fields' => [
                ['key' => 'name', 'label' => 'Company Name'],
                ['key' => 'domain', 'label' => 'Domain'],
                ['key' => 'phone', 'label' => 'Phone'],
                ['key' => 'external_id', 'label' => 'External Company ID'],
                ['key' => 'custom_field', 'label' => 'Custom Field', 'custom' => true],
            ],
        ],
    ],

    /**
     * What a Space starts with the first time Ticket Metadata Mapping is switched on.
     *
     * The requirement's own table (P75 §3), minus its two Custom Field rows: those name a field
     * that may not exist yet, and a seeded mapping pointing at nothing would fail validation the
     * moment somebody opened the page. Seeded rather than left empty because every one of these
     * is information an email already carries, and an empty table asks somebody to re-derive
     * that from scratch before the feature does anything at all.
     */
    'mapping_defaults' => [
        ['source' => 'sender_email', 'record_type' => 'customer', 'destination' => 'email'],
        ['source' => 'sender_name', 'record_type' => 'customer', 'destination' => 'name'],
        ['source' => 'sender_phone', 'record_type' => 'customer', 'destination' => 'phone'],
        ['source' => 'external_customer_id', 'record_type' => 'customer', 'destination' => 'external_id'],
        ['source' => 'company_name', 'record_type' => 'company', 'destination' => 'name'],
        ['source' => 'email_domain', 'record_type' => 'company', 'destination' => 'domain'],
        ['source' => 'external_company_id', 'record_type' => 'company', 'destination' => 'external_id'],
    ],

    /** The most mappings one Space may hold — a guard on the form, not a product limit. */
    'mapping_max' => 60,

    /**
     * The ways a customer can reach a Space (P12) — Settings → Channel.
     *
     * A LIST, not a set of switches. Email is the only channel that exists, and the other two
     * are named here rather than left off because "what else is coming?" is the question the
     * page is actually asked; an empty page with one row answers it with silence.
     *
     * `status` is the same vocabulary the settings nav uses: `active` is built, `soon` is not.
     * Nothing here is stored per Space — a Space's email channel is on because its Inbox and
     * inbound address exist, which is a fact about the Space rather than a preference somebody
     * set, and the moment there are two real channels this becomes a stored map instead.
     */
    'channels' => [
        [
            'key' => 'email',
            'label' => 'Email Support',
            'help' => 'Receive and respond to customer requests by email.',
            'status' => 'active',
        ],
        [
            'key' => 'chat',
            'label' => 'Chat Support',
            'help' => 'Provide real-time chat support to customers.',
            'status' => 'soon',
        ],
        [
            'key' => 'omnichannel',
            'label' => 'Omnichannel Support',
            'help' => 'Manage customer conversations across multiple channels — chat, messaging, social and more.',
            'status' => 'soon',
        ],
    ],

    /**
     * The most addresses one Space's Auto BCC may hold (P13).
     *
     * A cap rather than none: every one of these is a copy of every message the Space sends, so
     * the list is a mail multiplier, and "someone pasted forty addresses" should be refused by
     * the setting rather than discovered in a bill.
     */
    'auto_bcc_max' => 10,

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

    /**
     * Files arriving on an inbound email (P66).
     *
     * A DENY-list, unlike work item attachments, which use an allow-list. The difference is who
     * is sending: a colleague uploading to a work item can be told "that type is not accepted"
     * and pick another, but a customer emailing support cannot — silently dropping their file
     * leaves an agent reading "see attached" with nothing attached, which is the failure this
     * feature exists to fix. So everything is kept except the handful of types that are only
     * ever dangerous, and anything refused is COUNTED on the message rather than vanishing.
     *
     * Compared case-insensitively against the final extension of the sender's filename.
     */
    'attachments' => [
        'max_kb' => 25600,        // 25 MB per file — Postmark's own inbound ceiling is 35 MB total.
        'max_per_message' => 25,
        'blocked_extensions' => [
            'exe', 'com', 'bat', 'cmd', 'scr', 'pif', 'msi', 'msp', 'cpl', 'dll',
            'jar', 'vbs', 'vbe', 'js', 'jse', 'wsf', 'wsh', 'ps1', 'psm1',
            'sh', 'bash', 'app', 'scpt', 'hta', 'reg', 'lnk',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | SLA (docs/features/helpdesk-sla.md)
    |--------------------------------------------------------------------------
    | The SLA subsystem's fixed vocabulary. Config for the reason everything else in this file
    | is: these lists are read by the settings forms, the request that validates them, the
    | engine that acts on them and the panel that explains a stored value — four readers that
    | must not be able to disagree about what `pause` or `percent_90` means.
    */

    /** What a workflow status does to the running clocks (§19). */
    'sla_behaviors' => [
        'continue' => [
            'label' => 'Continue SLA',
            'description' => 'The clocks keep running while a Request sits here.',
        ],
        'pause' => [
            'label' => 'Pause SLA',
            'description' => 'Every running clock stops, and resumes with its remaining time.',
        ],
        'complete_resolution' => [
            'label' => 'Complete Resolution SLA',
            'description' => 'The Resolution clock is met here. Response clocks stop with it.',
        ],
        'stop' => [
            'label' => 'Stop SLA',
            'description' => 'The clocks end without being met — the Request has left the queue.',
        ],
    ],

    /**
     * The three clocks (§9–§11).
     *
     * `repeats` is the whole difference between them: Next Response starts again on every
     * customer reply, which is why timers carry a cycle number and the other two never leave 1.
     */
    'sla_timer_kinds' => [
        'first_response' => ['label' => 'First Response', 'repeats' => false],
        'next_response' => ['label' => 'Next Response', 'repeats' => true],
        'resolution' => ['label' => 'Resolution', 'repeats' => false],
    ],

    /** §16's six states, and whether the clock is still moving in each. */
    'sla_timer_statuses' => [
        'not_started' => ['label' => 'Not Started', 'color' => '#9ca3af', 'running' => false],
        'running' => ['label' => 'On Track', 'color' => '#22c55e', 'running' => true],
        'due_soon' => ['label' => 'Due Soon', 'color' => '#f59e0b', 'running' => true],
        'paused' => ['label' => 'Paused', 'color' => '#6b7280', 'running' => false],
        'completed' => ['label' => 'Completed', 'color' => '#3b82f6', 'running' => false],
        'breached' => ['label' => 'Breached', 'color' => '#ef4444', 'running' => false],
    ],

    /**
     * The units a target may be authored in (§8).
     *
     * `days` is a BUSINESS day — the calendar's own working length for that date, not 24 hours
     * and not a fixed eight (SLA-D7). That is why the unit is stored rather than flattened to
     * minutes at save time.
     */
    'sla_units' => [
        'minutes' => ['label' => 'Minutes'],
        'hours' => ['label' => 'Hours'],
        'days' => ['label' => 'Business Days'],
    ],

    /** What a resolved ticket's Resolution clock does when the customer comes back (§23). */
    'sla_reopen_behaviors' => [
        'resume' => ['label' => 'Resume Existing Resolution SLA'],
        'restart' => ['label' => 'Start New Resolution SLA'],
        'none' => ['label' => 'Do Not Restart Resolution SLA'],
    ],

    /**
     * "SLA Applies When" (§12).
     *
     * `source` tells the condition editor where the value picker gets its options, and tells the
     * matcher what it is comparing: `list` is an id from a table, `vocabulary` is a key from this
     * config file, and `custom_field` is a value stored against the ticket's Customer or Company.
     *
     * FOUR of the requirement's conditions are absent, and their absence is the decision
     * SLA-D6 records: Customer Type, Ticket Type, Ticket Channel and Support Plan are not things
     * this product stores. A ticket has no type and no channel column (every Request arrives by
     * email), and there is no support-plan concept anywhere. Offering them would be four
     * dropdowns with nothing to select and a matcher that could only ever return false.
     *
     * Three of the four are already expressible: Company & Customer custom fields (P75) are
     * exactly where a team records "Enterprise", "Gold Plan" or "Billing", and the two
     * `*_field` conditions below match against them.
     */
    'sla_condition_fields' => [
        'company' => ['label' => 'Company', 'source' => 'list'],
        /*
         * The customer by EMAIL, not by id.
         *
         * A Space has thousands of customers and a handful of companies, so a customer picker
         * would be a dropdown nobody can scroll — and email is how a customer is identified
         * everywhere else in this module, including on Requests that were never matched to a
         * customer row at all.
         */
        'customer_email' => ['label' => 'Customer Email', 'source' => 'text'],
        'priority' => ['label' => 'Ticket Priority', 'source' => 'vocabulary', 'vocabulary' => 'priorities'],
        'tag' => ['label' => 'Tag', 'source' => 'list'],
        'customer_field' => ['label' => 'Customer Field', 'source' => 'custom_field', 'record' => 'customer'],
        'company_field' => ['label' => 'Company Field', 'source' => 'custom_field', 'record' => 'company'],
    ],

    /**
     * How a condition compares.
     *
     * `is` and `is_not` take a SET of values — "Company is one of Acme, Globex" is one condition,
     * not two, and modelling it as two would force the author to pick `any` for the whole policy
     * just to express it.
     */
    'sla_condition_operators' => [
        'is' => ['label' => 'is', 'multiple' => true],
        'is_not' => ['label' => 'is not', 'multiple' => true],
        'contains' => ['label' => 'contains', 'multiple' => false],
        'is_set' => ['label' => 'is set', 'multiple' => false, 'valueless' => true],
        'is_not_set' => ['label' => 'is not set', 'multiple' => false, 'valueless' => true],
    ],

    /**
     * When an escalation fires (§24).
     *
     * Five triggers, not the requirement's eight: "First Response SLA Breached" is `breached`
     * with the rule's `kind` set to `first_response`. Spelling each stage out as its own trigger
     * would mean nine more the day somebody wants "First Response reaches 90%".
     */
    'sla_escalation_triggers' => [
        'percent_50' => ['label' => 'SLA reaches 50%', 'percent' => 50],
        'percent_75' => ['label' => 'SLA reaches 75%', 'percent' => 75],
        'percent_90' => ['label' => 'SLA reaches 90%', 'percent' => 90],
        'due_soon' => ['label' => 'SLA Due Soon', 'percent' => null],
        'breached' => ['label' => 'SLA Breached', 'percent' => null],
    ],

    /**
     * What an escalation does (§25).
     *
     * `needs` says what the action requires beside its name — a rule that changes priority needs
     * to know which one, and one that notifies the assignee needs nothing.
     *
     * NOT called `value`. Every vocabulary here reaches the browser as `['value' => $key] +
     * $entry`, and PHP's `+` keeps the LEFT operand's keys — an entry key called `value` would
     * be silently dropped on the way out, and the picker would render a text box for every
     * action because none of them appeared to need anything.
     *
     * "Change Team" points at a Department Group: that is what this product has instead of
     * teams, and there is nothing else for it to mean.
     */
    'sla_escalation_actions' => [
        'notify_assignee' => ['label' => 'Notify Assignee', 'needs' => null],
        'notify_space_admin' => ['label' => 'Notify Space Admin', 'needs' => null],
        'notify_team_lead' => ['label' => 'Notify Team Lead', 'needs' => null],
        'send_email' => ['label' => 'Send Email', 'needs' => 'email'],
        'change_assignee' => ['label' => 'Change Assignee', 'needs' => 'member'],
        'change_team' => ['label' => 'Change Team', 'needs' => 'group'],
        'change_priority' => ['label' => 'Change Priority', 'needs' => 'priority'],
        'change_status' => ['label' => 'Change Status', 'needs' => 'status'],
        'add_tag' => ['label' => 'Add Tag', 'needs' => 'tag'],
        'remove_tag' => ['label' => 'Remove Tag', 'needs' => 'tag'],
    ],

    /**
     * The working week a new Business Hours calendar starts as — §5's example, exactly.
     *
     * A default that is somebody's real week beats an empty form: the common case is
     * "weekdays, office hours", and a Space that wants something else edits five fields instead
     * of authoring seven.
     */
    'sla_default_schedule' => [
        'mon' => ['open' => '08:00', 'close' => '18:00'],
        'tue' => ['open' => '08:00', 'close' => '18:00'],
        'wed' => ['open' => '08:00', 'close' => '18:00'],
        'thu' => ['open' => '08:00', 'close' => '18:00'],
        'fri' => ['open' => '08:00', 'close' => '18:00'],
        'sat' => null,
        'sun' => null,
    ],

    /** The days of the week, in the order the form draws them. */
    'sla_week_days' => [
        'mon' => 'Monday',
        'tue' => 'Tuesday',
        'wed' => 'Wednesday',
        'thu' => 'Thursday',
        'fri' => 'Friday',
        'sat' => 'Saturday',
        'sun' => 'Sunday',
    ],

];
