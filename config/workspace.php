<?php

/*
|--------------------------------------------------------------------------
| Workspace creation options (Phase 3 — Create Workspace)
|--------------------------------------------------------------------------
| Central source of truth for the workspace create + invite flows. Mirrors
| the values rendered by the HTML POC and enforced server-side (spec §4-§7).
*/

return [
    /**
     * Team size buckets (WS-006). The stored value is the label itself.
     */
    'team_sizes' => ['Just myself', '2-10', '11-50', '51-200', '201-500', '500+'],

    /**
     * Workspace view types (spec §5).
     * - agile: available and the only creatable view this release (WS-VIEW-001).
     * - classic: Coming Soon — visible-but-disabled; never selectable or creatable
     *   until the feature flag flips (WS-VIEW-002/003/005).
     */
    'views' => [
        'agile' => [
            'label' => 'Agile',
            'description' => 'Sprints, story points, and a backlog for scrum.',
            'available' => true,
        ],
        'classic' => [
            'label' => 'Classic',
            'description' => 'Lists, boards, and cycles — the familiar way.',
            'available' => false, // Coming Soon
        ],
    ],

    'default_view' => 'agile',

    /**
     * Apps enabled under a workspace (multi-select on the create flow). Projects is
     * available today; the rest are Coming Soon — visible but not yet selectable. As each
     * app ships, flip `available` to true and it becomes a real selectable toggle.
     */
    'apps' => [
        'projects' => [
            'label' => 'Projects',
            'description' => 'Plan projects, tasks, milestones, cycles, owners, timelines, and team delivery.',
            'available' => true,
        ],
        'wiki' => [
            'label' => 'Wiki',
            'description' => 'Create internal documentation, knowledge bases, guides, policies, SOPs, and shared team knowledge.',
            'available' => true,
        ],
        'helpdesk' => [
            'label' => 'Help Desk',
            'description' => 'Manage customer support requests, tickets, conversations, assignments, SLAs, and resolution workflows.',
            'available' => false,
        ],
        'clienthub' => [
            'label' => 'Client Hub',
            'description' => 'Create customer-facing workspaces for sales deals, onboarding, implementation, and ongoing customer collaboration.',
            'available' => false,
        ],
    ],

    'default_apps' => ['projects'],

    /**
     * Feature flag for Classic (WS-VIEW-005). Flip to true — no data migration of
     * existing Agile workspaces required — to release Classic later.
     */
    'classic_enabled' => env('WORKSPACE_CLASSIC_ENABLED', false),

    /**
     * Membership roles. Owner is assigned to the creator only and is never an invite
     * option (spec §6). The remaining four are the selectable invite roles.
     */
    'roles' => [
        'owner' => 'Owner',
        'admin' => 'Admin',
        // Manager comes from the Project Member Management spec §25. Added alongside the
        // existing roles rather than replacing Member, so no live membership is migrated.
        // It carries no extra workspace privileges of its own: per §17 a Manager only
        // manages project members when they are also that project's Admin.
        'manager' => 'Manager',
        'member' => 'Member',
        'viewer' => 'Viewer',
        'guest' => 'Guest',
    ],

    'invite_roles' => ['admin', 'manager', 'member', 'viewer', 'guest'],

    /**
     * Reserved slugs blocked from workspace URLs (WS-004). Keeps workspace slugs from
     * colliding with application/system routes and marketing paths.
     */
    'reserved_slugs' => [
        'admin', 'api', 'app', 'auth', 'login', 'logout', 'signin', 'signup', 'verify',
        'onboarding', 'workspace', 'workspaces', 'settings', 'billing', 'support', 'help',
        'dashboard', 'home', 'welcome', 'new', 'create', 'about', 'pricing', 'blog',
        'status', 'assets', 'public', 'storage', 'emaillog', 'www', 'mail', 'static',
        // The application's own top-level paths. A published wiki collection is served from
        // `/{workspace}/{slug}`, which is matched LAST — but a workspace named `wiki` would
        // still make `/wiki/...` ambiguous to read, and ambiguity in a URL is a bug waiting
        // for someone to name their workspace badly.
        'wiki', 'projects', 'project', 'inbox', 'drafts', 'your-work', 'session', 'access',
        'invitations', 'account', 'reports',
    ],

    /**
     * Invitations expire after this many days.
     */
    'invitation_expiry_days' => 14,

    /**
     * Member seats per workspace — active members plus outstanding invitations (invite spec
     * §10/§65). `null` means unlimited, which is the current state of the product: there is
     * no subscription model yet, so this is the single place that decides capacity. When
     * billing lands, WorkspaceSeatGuard reads the workspace's plan instead and this becomes
     * the fallback (docs/features/member-invite-flow.md D-I3).
     */
    'seat_limit' => env('WORKSPACE_SEAT_LIMIT') === null ? null : (int) env('WORKSPACE_SEAT_LIMIT'),

    /**
     * Interface languages (Account §2).
     *
     * One entry today. It is a list rather than a hardcoded 'English' because the control is
     * a searchable combobox either way, and adding a language pack should be a line here
     * rather than a change to a form — `available` is what tells the picker which ones can
     * actually be chosen, so a coming-soon language can be shown without being selectable.
     */
    'languages' => [
        ['value' => 'en', 'label' => 'English', 'available' => true],
    ],

    /**
     * The days of the week, as ISO-8601 numbers (1 = Monday … 7 = Sunday).
     *
     * ISO because it is the numbering PHP, JavaScript's `getDay`-adjacent APIs and every date
     * library already agree on, so a stored value never needs translating between them. The
     * labels here are for display only.
     */
    'week_days' => [
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
        7 => 'Sunday',
    ],

    /** Defaults for a workspace that has never opened the Preference tab. */
    'week_defaults' => [
        'first_day' => 7,
        'weekend' => [6, 7],
    ],
];
