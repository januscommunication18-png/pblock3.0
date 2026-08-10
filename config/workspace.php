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
            'available' => false,
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
        'member' => 'Member',
        'viewer' => 'Viewer',
        'guest' => 'Guest',
    ],

    'invite_roles' => ['admin', 'member', 'viewer', 'guest'],

    /**
     * Reserved slugs blocked from workspace URLs (WS-004). Keeps workspace slugs from
     * colliding with application/system routes and marketing paths.
     */
    'reserved_slugs' => [
        'admin', 'api', 'app', 'auth', 'login', 'logout', 'signin', 'signup', 'verify',
        'onboarding', 'workspace', 'workspaces', 'settings', 'billing', 'support', 'help',
        'dashboard', 'home', 'welcome', 'new', 'create', 'about', 'pricing', 'blog',
        'status', 'assets', 'public', 'storage', 'emaillog', 'www', 'mail', 'static',
    ],

    /**
     * Invitations expire after this many days.
     */
    'invitation_expiry_days' => 14,
];
