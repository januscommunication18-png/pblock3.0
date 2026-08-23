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
        /*
         * The KEY stays `helpdesk` and its flag stays `help_desk_enabled` (HC-D2): both carry
         * live data, and renaming them would be a migration over production rows to change a
         * string nobody sees. The label is what people read, and it says Help Center.
         */
        'helpdesk' => [
            'label' => 'Help Center',
            'description' => 'Manage customer support requests, tickets, conversations, assignments, SLAs, and resolution workflows.',
            'available' => true,
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
    /**
     * The tenant SUBDOMAIN (docs/features/workspace-subdomain.md, P72).
     *
     * `acme` in `https://acme.projectblock.app`. One identifier per tenant, and the products
     * are separated by ROUTE underneath it — `/help`, `/client` — rather than by giving each
     * its own host. That is the requirement's own recommendation and it is the right one: a
     * tenant gets one customer-facing domain to publish, and Help Center and Client Hub cannot
     * collide over who owns `acme`.
     *
     * Deliberately NOT the same thing as `slug`. The slug is this workspace's path on the
     * shared application host (`app.projectblock.so/acme-inc`) and every workspace has one; the
     * subdomain is a customer-facing host and only a workspace running a customer-facing
     * product needs one. Merging them would put two inputs on one column and force every
     * tenant to accept their internal path as their public brand.
     */
    'subdomain' => [
        /*
         * The zone the tenant's label is added to. Env so staging can differ from production
         * without a code change, which is also what makes this testable.
         *
         * HOST ONLY — no port. `Route::domain()` matches `$request->getHost()`, which excludes
         * the port, so a root of `localhost:8000` would compile to a pattern that can never
         * match. The port belongs to display, and lives below.
         */
        'root' => env('TENANT_ROOT_DOMAIN', 'projectblock.app'),
        'scheme' => env('TENANT_ROOT_SCHEME', 'https'),

        /*
         * Appended when BUILDING a url, never when matching one.
         *
         * Only needed locally, where the dev server is on a port: `TENANT_ROOT_PORT=8000` makes
         * the previews read `http://acme.localhost:8000` while routing still matches
         * `acme.localhost`. Null in production, where the port is implied by the scheme.
         */
        'port' => env('TENANT_ROOT_PORT'),

        /*
         * 3 to 63. The ceiling is not a preference: 63 octets is the maximum length of a single
         * DNS label (RFC 1035), so a longer one could never be resolved whatever we stored.
         */
        'min' => 3,
        'max' => 63,

        /*
         * The apps that make a tenant customer-facing, and therefore require one.
         *
         * `clienthub` is listed although it is not released yet — the rule is about what the
         * app IS, and a list that has to be remembered on release day is a list that will not
         * be.
         */
        'required_by' => ['helpdesk', 'clienthub'],

        /**
         * Hosts that must never belong to a tenant.
         *
         * A superset of `reserved_slugs` in spirit but a different list, because it protects
         * something different: these are names that resolve, or will resolve, somewhere of our
         * own. `www` and `mail` are the obvious ones; `status` and `billing` are pages people
         * will expect at the root; `autodiscover`/`autoconfig` are what mail clients probe for;
         * `_domainkey`/`dmarc` are where mail authentication lives, and a tenant owning one
         * could break signing for the whole zone.
         */
        'reserved' => [
            'www', 'admin', 'api', 'app', 'apps', 'mail', 'smtp', 'imap', 'pop', 'webmail',
            'support', 'help', 'helpdesk', 'billing', 'status', 'backoffice', 'dashboard',
            'account', 'accounts', 'auth', 'login', 'logout', 'signin', 'signup', 'register',
            'assets', 'static', 'cdn', 'media', 'files', 'download', 'downloads',
            'blog', 'docs', 'developer', 'developers', 'partner', 'partners',
            'staging', 'dev', 'test', 'demo', 'sandbox', 'preview', 'beta', 'alpha',
            'ns', 'ns1', 'ns2', 'dns', 'mx', 'autodiscover', 'autoconfig', 'dmarc',
            '_domainkey', 'dkim', 'spf', 'vpn', 'ssh', 'ftp', 'git',
            'projectblock', 'inbound', 'client', 'clienthub', 'security', 'legal', 'privacy',
        ],
    ],

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
