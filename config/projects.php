<?php

/*
|--------------------------------------------------------------------------
| Project creation options (Phase 4 — Create Project)
|--------------------------------------------------------------------------
| Single source of truth for the Create Project flow. Mirrors the values in
| the HTML POC (html/projects.html) and is enforced server-side (spec §4-§7).
*/

return [
    /**
     * Project name length cap (PRJ-021). Names may repeat within a workspace (spec §5).
     */
    'name_max' => 80,

    /**
     * Project identifier (PRJ-022/023). Uppercase A-Z/0-9, unique within the workspace.
     * The POC auto-derives up to 10 chars from the name; the column allows a little slack.
     */
    'identifier_max' => 10,

    /**
     * Access options (PRJ-025). Default Public to match the prototype (spec §4.2).
     */
    'visibilities' => [
        'public' => 'Public',
        'private' => 'Private',
    ],

    'default_visibility' => 'public',

    /**
     * Description length cap (PRJ-024, optional).
     */
    'description_max' => 2000,

    /**
     * Cover upload constraints (PRJ-027). Common web image formats; recoverable errors on
     * invalid type / oversize.
     */
    /**
     * Images uploaded from the rich-text editor (its image button and gallery picker).
     * Stored on the private disk and streamed through an authorized route, so these limits
     * are the only thing standing between the editor and the filesystem.
     */
    'media' => [
        'max_kb' => 5120,
        'mimes' => ['jpg', 'jpeg', 'png', 'webp', 'gif'],
        'gallery_size' => 60,
    ],

    'cover' => [
        'max_kb' => 5120,
        'mimes' => ['jpg', 'jpeg', 'png', 'webp', 'gif'],
    ],

    /**
     * Deterministic gradient palette for coverless project cards (POC card styling). The
     * card picks one by hashing the project identifier so a project's tile stays stable.
     */
    'cover_gradients' => [
        'linear-gradient(120deg,#0b0b0d 0%,#7f1d1d 55%,#0e7490 100%)',
        'linear-gradient(120deg,#f6d5b8 0%,#eaa987 55%,#d98a68 100%)',
        'linear-gradient(120deg,#1e3a8a 0%,#3b82f6 55%,#22d3ee 100%)',
        'linear-gradient(120deg,#111827 0%,#4c1d95 55%,#7c3aed 100%)',
        'linear-gradient(120deg,#064e3b 0%,#059669 55%,#a3e635 100%)',
        'linear-gradient(120deg,#7c2d12 0%,#ea580c 55%,#f59e0b 100%)',
        'linear-gradient(120deg,#831843 0%,#be185d 55%,#f472b6 100%)',
        'linear-gradient(120deg,#0f172a 0%,#334155 55%,#94a3b8 100%)',
    ],

    /**
     * Project-level roles (Project Member Management §3/§10/§25). Distinct from workspace
     * roles: workspace membership says you belong to the organisation, project membership
     * says you participate in this project and what you may do inside it (§38).
     *
     * `ProjectMembersController` validates against these keys, and the Add Member modal
     * shows the description under each option (§10).
     */
    'roles' => [
        'admin' => [
            'label' => 'Admin',
            'description' => 'Can manage project settings, members, and all project work.',
        ],
        'contributor' => [
            'label' => 'Contributor',
            'description' => 'Can create and update project work.',
        ],
        'commenter' => [
            'label' => 'Commenter',
            'description' => 'Can view project work and participate through comments.',
        ],
        'guest' => [
            'label' => 'Guest',
            'description' => 'Limited access to permitted project information.',
        ],
    ],

    /** Default selection in the Add Member modal (§10). */
    'default_role' => 'contributor',

    /** Project roles allowed to create and edit work items (§34 permission matrix). */
    'contributor_roles' => ['admin', 'contributor'],

    /**
     * Project Settings sections (PRJ-040..044), in sidebar order. `ProjectSettingsController`
     * looks a section up here before rendering, so a key missing from this list 404s — this
     * IS the registry of project-settings screens, not just their labels.
     *
     * Estimates and Automations are Coming Soon: they render the shared placeholder page.
     */
    'settings_nav' => [
        ['key' => 'general', 'label' => 'General', 'status' => 'active'],
        ['key' => 'members', 'label' => 'Members', 'status' => 'active'],
        // The key stays `features` — it is the URL, the route name and the toggle endpoint.
        // Only the label changes: everything this section configures is Cycles.
        ['key' => 'features', 'label' => 'Cycle', 'status' => 'active'],
        ['key' => 'states', 'label' => 'States', 'status' => 'active'],
        ['key' => 'labels', 'label' => 'Labels', 'status' => 'active'],
        ['key' => 'estimates', 'label' => 'Estimates', 'status' => 'soon'],
        ['key' => 'automations', 'label' => 'Automations', 'status' => 'soon'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Project Workspace — Work Items (Phase 5)
    |--------------------------------------------------------------------------
    */

    /**
     * Project Workspace tab bar, in the order required by the Work Items spec §3.
     *
     * `status` here is the DEFAULT. Feature-gated tabs are resolved per project by
     * ProjectNavigation::tabs() — Cycles becomes functional where the project has the feature
     * on and disappears where it does not (Cycles §3.2.3/§3.2.4). The rest render a Coming
     * Soon page but stay visible so the information architecture is legible.
     */
    'workspace_tabs' => [
        ['key' => 'overview', 'label' => 'Overview', 'status' => 'soon'],
        ['key' => 'work-items', 'label' => 'Work items', 'status' => 'active'],
        // Resolved per project — see ProjectNavigation::tabs().
        ['key' => 'cycles', 'label' => 'Cycles', 'status' => 'soon'],
        ['key' => 'modules', 'label' => 'Modules', 'status' => 'soon'],
        ['key' => 'views', 'label' => 'Views', 'status' => 'soon'],
        ['key' => 'pages', 'label' => 'Pages', 'status' => 'soon'],
    ],

    /**
     * Work-item states seeded per project on first use of the Work Items screen. `group` is
     * the stable key the list groups and orders by; `name` stays user-editable in Project
     * Settings → States without breaking the grouping (spec §4.2).
     */
    'default_item_states' => [
        ['name' => 'Backlog', 'color' => '#9CA3AF', 'group' => 'backlog', 'is_default' => true],
        ['name' => 'Todo', 'color' => '#6B7280', 'group' => 'unstarted', 'is_default' => false],
        ['name' => 'In Progress', 'color' => '#F59E0B', 'group' => 'started', 'is_default' => false],
        ['name' => 'Done', 'color' => '#22C55E', 'group' => 'completed', 'is_default' => false],
        ['name' => 'Cancelled', 'color' => '#EF4444', 'group' => 'cancelled', 'is_default' => false],
    ],

    /**
     * Work-item priorities (spec §4.3). A fixed vocabulary stored as a string column — these
     * are not the workspace-configurable `project_priorities` used by project cards.
     */
    'work_item_priorities' => [
        'urgent' => 'Urgent',
        'high' => 'High',
        'medium' => 'Medium',
        'low' => 'Low',
        'none' => 'None',
    ],

    'work_item_title_max' => 255,

    'work_item_description_max' => 20000,

    /** Page size for the work item list. */
    'work_item_page_size' => 250,

    /**
     * Project Settings → Features (PRJ-042). The catalog is config, the on/off state is a
     * JSON map on the project — so adding a feature here makes it readable on every existing
     * project without a migration or a backfill.
     *
     * `requires` names a feature that must be on first (Cycles §3.3.1); `entitlement` names a
     * key in `entitlements` below, which is checked SERVER-side, not only in the UI (§13).
     */
    'features' => [
        'cycles' => [
            'label' => 'Cycles',
            'description' => 'Let this project plan work in cycles.',
            'default' => false,
        ],
        'parallel_cycles' => [
            'label' => 'Parallel cycles',
            'description' => 'Run more than one cycle at a time, useful when teams work on separate streams.',
            'default' => false,
            'requires' => 'cycles',
            'entitlement' => 'parallel_cycles',
        ],
    ],

    /**
     * Paid-plan gates (Cycles §13). This app has no subscription model yet, so the answer is
     * a config value standing in for one — swap the body of Project::entitledTo() when a real
     * plan check exists and nothing else has to move.
     */
    'entitlements' => [
        'parallel_cycles' => true,
    ],

    /** Cycles (sprints) — Cycles §6.1. */
    'cycle_name_max' => 120,

    'cycle_description_max' => 2000,
];
