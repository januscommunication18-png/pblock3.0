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
        ['key' => 'modules', 'label' => 'Module', 'status' => 'active'],
        ['key' => 'epics', 'label' => 'Epic', 'status' => 'active'],
        ['key' => 'pages', 'label' => 'Pages', 'status' => 'active'],
        ['key' => 'states', 'label' => 'States', 'status' => 'active'],
        ['key' => 'labels', 'label' => 'Labels', 'status' => 'active'],
        ['key' => 'estimates', 'label' => 'Estimation', 'status' => 'active'],
        ['key' => 'views', 'label' => 'View', 'status' => 'active'],
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
     * on and disappears where it does not (Cycles §3.2.3/§3.2.4).
     *
     * Settings is deliberately NOT in this list. It is a dropdown rather than a page, and its
     * route takes a `section`, so it is rendered directly by partials/project-tabs — every key
     * here is resolved with `route('projects.'.$key, $project)`, which a section-less route
     * name cannot satisfy.
     */
    'workspace_tabs' => [
        // Every tab links to a real screen. The four that used to carry `soon` — Epics,
        // Cycles, Modules, Pages — have had their own controllers and routes for some time;
        // the status was simply never updated, so the bar went on advertising built features
        // as unfinished. Whether each one is AVAILABLE is a separate question, answered per
        // project by ProjectNavigation::tabs() from the feature flags.
        ['key' => 'overview', 'label' => 'Overview', 'status' => 'active'],
        ['key' => 'work-items', 'label' => 'Work items', 'status' => 'active'],
        ['key' => 'epics', 'label' => 'Epics', 'status' => 'active'],
        ['key' => 'cycles', 'label' => 'Cycles', 'status' => 'active'],
        ['key' => 'modules', 'label' => 'Modules', 'status' => 'active'],
        ['key' => 'views', 'label' => 'Views', 'status' => 'active'],
        ['key' => 'pages', 'label' => 'Pages', 'status' => 'active'],
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
     * The background behind someone's initial when they have not uploaded a photo.
     *
     * ONE list, because the same person has to be the same colour in a work item row, a member
     * table, a comment and the topbar — and this screen's badge is drawn by PHP while that
     * one's is drawn by Vue. `User::avatarColor()` and `PB.avatarColor()` both read this order
     * and both hash the user's ID with djb2, so the two agree by construction; the JS copy is
     * pinned to this array by a test.
     *
     * Every colour clears **4.5:1 against white text** (WCAG AA for small text) — checked, not
     * assumed. The obvious mid-tone picks do not: Tailwind's orange-600 (3.56:1), emerald-600
     * (3.77:1) and teal-600 (3.74:1) all failed and were stepped to their 700s. The worst
     * survivor is pink at 4.60:1.
     *
     * Keyed on the ID rather than the name or email, so renaming yourself — or changing your
     * address — does not repaint you.
     */
    'avatar_colors' => [
        '#2563EB', '#DB2777', '#047857', '#7C3AED', '#C2410C', '#0E7490',
        '#C026D3', '#B45309', '#4F46E5', '#E11D48', '#0F766E', '#475569',
    ],

    /**
     * The five state GROUPS, for anything that summarises work rather than listing it
     * (Your Work → Summary). A state's name is user-editable per project; its group is not,
     * so a group is the only thing that can be counted across projects.
     *
     * The labels are the reading names — "Not started", "Working on" — rather than the stable
     * keys, which are internal vocabulary and should not surface in a legend.
     *
     * COLOURS are a status palette, not a categorical one: they mean a state of work, they are
     * the same ones `wiStateIcon` draws with, and they are never reused for "series 4". Every
     * mark that carries one also carries its label and its count, so identity is never colour
     * alone — which is what the swatches, the donut legend and the tiles all do.
     *
     * `unstarted` is blue rather than the seeded grey of the "Todo" state: against Backlog's
     * grey the two were a lightness step apart and both read as grey (measured — OKLCh chroma
     * 0.019 and 0.023, under the floor), which is unreadable in a row of 12px swatches.
     *
     * KNOWN, MEASURED, NOT FIXED HERE: `started` #F59E0B and `completed` #22C55E sit ΔE 5.7
     * apart under protanopia — below the ≥8 target. Stepping the green to #059669 clears it
     * (ΔE 9.6), but #22C55E is the app's green in every state icon and progress ring, so that
     * is a design-system change rather than this screen's to make. Until then the labels are
     * what carry the distinction. See docs/features/your-work.md.
     */
    'state_groups' => [
        ['key' => 'backlog', 'label' => 'Backlog', 'color' => '#9CA3AF'],
        ['key' => 'unstarted', 'label' => 'Not started', 'color' => '#3B82F6'],
        ['key' => 'started', 'label' => 'Working on', 'color' => '#F59E0B'],
        ['key' => 'completed', 'label' => 'Completed', 'color' => '#22C55E'],
        ['key' => 'cancelled', 'label' => 'Cancelled', 'color' => '#EF4444'],
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
     * How far back Your Work's Activity tab reads. Shorter than the work item page size on
     * purpose: it is a record of what you have been doing lately, not an audit log — that
     * lives on each work item, in full, and is never trimmed.
     */
    'your_work_activity_size' => 100,

    /**
     * Rows per request for a View's grid (Views §24).
     *
     * Smaller than the work item list's 250 on purpose: that screen loads one capped batch and
     * is done, whereas a View fetches the next page as you scroll, so the number that matters
     * is how fast the FIRST screenful arrives.
     */
    'view_page_size' => 100,

    /** §6.1 — a View's name. */
    'view_name_max' => 120,

    /**
     * §12.5's density, as the row height each one means. Stored on the View and persisted
     * with everything else (§13), so a user's choice survives reopening it.
     *
     * Standard is 44px because that is what the work items list uses (`rowHeight` in
     * work-item-list.js). The two grids render the same rows from the same chip helpers, so a
     * work item that changed height depending on which screen you opened it from would give
     * away that they are two grids — and the whole point of sharing the renderers is that it
     * should not be visible. Compact and Comfortable are steps either side of that, not an
     * independent scale.
     */
    'view_densities' => [
        'compact' => ['label' => 'Compact', 'row_height' => 36],
        'standard' => ['label' => 'Standard', 'row_height' => 44],
        'comfortable' => ['label' => 'Comfortable', 'row_height' => 56],
    ],

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
            'singular' => 'Cycle',
            'description' => 'Let this project plan work in cycles.',
            'default' => false,
            'section' => 'features',
            // §7: disabling any of the three asks first, and says what stays.
            'confirm_disable' => true,
        ],
        'modules' => [
            'label' => 'Modules',
            'singular' => 'Module',
            'description' => 'Group related work items into focused initiatives or delivery areas within this project.',
            'default' => false,
            'section' => 'modules',
            'confirm_disable' => true,
        ],
        'epics' => [
            'label' => 'Epics',
            'singular' => 'Epic',
            'description' => 'Organize work items into larger initiatives and track progress across multiple areas and cycles.',
            'default' => false,
            'section' => 'epics',
            'confirm_disable' => true,
        ],
        'pages' => [
            'label' => 'Pages',
            'singular' => 'Page',
            'description' => 'Allow members of this project to create and manage project documentation using Pages.',
            'default' => false,
            'section' => 'pages',
            'confirm_disable' => true,
            // Pages §5 restricts direct access to a disabled project's pages, unlike Epics,
            // Modules and Cycles whose records stay readable for historical reference. So the
            // tab goes entirely when the feature is off — keeping it would point at a 404.
            'hides_when_disabled' => true,
        ],
        'labels' => [
            'label' => 'Labels',
            'singular' => 'Label',
            'description' => 'Use labels to categorize and organize work items within this project.',
            // §2: ON by default, unlike every other optional feature. Labels are a basic way
            // to organise work rather than a planning layer a team opts into — and because
            // featureFlags() merges catalog defaults, every existing project keeps them.
            'default' => true,
            'section' => 'labels',
            'confirm_disable' => true,
        ],
        'views' => [
            'label' => 'Views',
            'singular' => 'View',
            'description' => 'Build configurable spreadsheet-style views of this project\'s work items.',
            'default' => false,
            'section' => 'views',
            'confirm_disable' => true,
        ],
        'milestones' => [
            'label' => 'Milestones',
            'singular' => 'Milestone',
            'description' => 'Track dated checkpoints for this project and see them alongside the overview.',
            'default' => false,
            'section' => 'milestones',
            'confirm_disable' => true,
            // Unlike Cycles/Modules/Epics, whose records stay readable for historical
            // reference when the feature is switched off, there is nothing yet to read — so
            // the segment goes entirely rather than pointing at an empty screen. Same reason
            // Pages carries this flag (Pages §5).
            'hides_when_disabled' => true,
        ],
        'estimates' => [
            'label' => 'Estimation',
            'singular' => 'Estimate',
            'description' => 'Use estimates to measure the relative effort, complexity, or expected time required to complete work items in this project.',
            'default' => false,
            'section' => 'estimates',
            'confirm_disable' => true,
        ],
        // Views §4.2's sub-settings. Sub-features of `views`, the same shape as
        // parallel_cycles: `requires` means the row only appears once Views is on, and
        // switching Views off takes them with it without touching their stored value.
        'view_project' => [
            'label' => 'Allow project views',
            'description' => 'Let members create views that everyone permitted on this project can open.',
            'default' => true,
            'requires' => 'views',
            'section' => 'views',
        ],
        'view_private' => [
            'label' => 'Allow private views',
            'description' => 'Let members create views only they can open.',
            'default' => true,
            'requires' => 'views',
            'section' => 'views',
        ],
        'parallel_cycles' => [
            'label' => 'Parallel cycles',
            'description' => 'Run more than one cycle at a time, useful when teams work on separate streams.',
            'default' => false,
            'requires' => 'cycles',
            'entitlement' => 'parallel_cycles',
            'section' => 'features',
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

    /**
     * Modules (Module Management §5.2/§6) — a container for related work items.
     *
     * The six statuses are lifecycle states, not work item states: they say where the module
     * itself is, and changing one never touches the work items inside it (§6.4/§6.6).
     */
    'module_statuses' => [
        'backlog' => ['label' => 'Backlog', 'color' => '#9ca3af'],
        'planned' => ['label' => 'Planned', 'color' => '#6366f1'],
        'in_progress' => ['label' => 'In Progress', 'color' => '#d97706'],
        'paused' => ['label' => 'Paused', 'color' => '#0891b2'],
        'completed' => ['label' => 'Completed', 'color' => '#22c55e'],
        'cancelled' => ['label' => 'Cancelled', 'color' => '#dc2626'],
    ],

    /** §5.2: a new module starts in Backlog unless the user picks otherwise. */
    'module_default_status' => 'backlog',

    'module_title_max' => 255,

    'module_description_max' => 2000,

    /**
     * The clip shown on the empty Modules screen. Drop a file in public/assets/video and
     * point this at it; until then the empty state renders a labelled placeholder rather
     * than a broken player.
     */
    'module_intro_video' => env('MODULE_INTRO_VIDEO'),

    /**
     * Epics (Epic §5/§6) — a larger initiative that work items contribute to.
     *
     * The same six lifecycle values as Modules, deliberately: §5 asks for the module naming
     * convention, and two planning dimensions that read differently for the same idea would be
     * a needless thing to learn. Stored, not derived — see docs/features/epics.md (E4).
     */
    'epic_statuses' => [
        'backlog' => ['label' => 'Backlog', 'color' => '#9ca3af'],
        'planned' => ['label' => 'Planned', 'color' => '#6366f1'],
        'in_progress' => ['label' => 'In Progress', 'color' => '#d97706'],
        'paused' => ['label' => 'Paused', 'color' => '#0891b2'],
        'completed' => ['label' => 'Completed', 'color' => '#22c55e'],
        'cancelled' => ['label' => 'Cancelled', 'color' => '#dc2626'],
    ],

    /** §5.2's equivalent for epics: a new epic starts in Backlog unless told otherwise. */
    'epic_default_status' => 'backlog',

    'epic_title_max' => 255,

    'epic_description_max' => 2000,

    /** The clip shown on the empty Epics screen — see `module_intro_video`. */
    'epic_intro_video' => env('EPIC_INTRO_VIDEO'),

    /**
     * Estimation systems (Estimation §5-§8).
     *
     * Three types, each with ready-made templates plus Custom. The templates are seed values,
     * not a constraint — §10 lets an admin add, rename, remove and reorder whatever they
     * started from, so a "Fibonacci" system whose values have since been edited is still a
     * valid Fibonacci system. `template` records where it came from, nothing more.
     *
     * `numeric` and `minutes` are what §32's future rollups will sum. A category has neither:
     * XS is not a number and cannot be added up, which is exactly why it can only be counted.
     */
    'estimate_types' => [
        'points' => [
            'label' => 'Points',
            'description' => 'Represent relative effort using numerical values.',
            'templates' => [
                'linear' => ['label' => 'Linear', 'values' => [1, 2, 3, 4, 5, 6]],
                'fibonacci' => ['label' => 'Fibonacci', 'values' => [1, 2, 3, 5, 8, 13]],
                'squares' => ['label' => 'Squares', 'values' => [1, 4, 9, 16, 25]],
                'custom' => ['label' => 'Custom', 'values' => [1, 2, 4, 8, 12, 20]],
            ],
        ],
        'category' => [
            'label' => 'Category',
            'description' => 'Estimate using descriptive values instead of numbers.',
            'templates' => [
                'tshirt' => ['label' => 'T-Shirt Size', 'values' => ['XS', 'S', 'M', 'L', 'XL']],
                'easy_to_hard' => ['label' => 'Easy to Hard', 'values' => ['Easy', 'Medium', 'Hard']],
                'custom' => ['label' => 'Custom', 'values' => ['Very Small', 'Small', 'Medium', 'Large', 'Very Large']],
            ],
        ],
        'time' => [
            'label' => 'Time',
            'description' => 'Estimate the expected amount of time required.',
            'templates' => [
                // Minutes, so 1d is 8h of work rather than 24 — an estimate is effort, and
                // nobody works a 24-hour day.
                'standard' => ['label' => 'Standard', 'values' => [
                    '30m' => 30, '1h' => 60, '2h' => 120, '4h' => 240, '8h' => 480, '1d' => 480, '2d' => 960,
                ]],
                'custom' => ['label' => 'Custom', 'values' => ['1h' => 60, '2h' => 120, '4h' => 240, '1d' => 480]],
            ],
        ],
    ],

    /**
     * Page statuses (§9). Draft is where a page starts — documentation is written before it
     * is ready to be read, and publishing is the moment someone decides it is.
     *
     * Separate from archiving, which answers "is this still current?" rather than "is this
     * ready?". A published page can be archived without becoming a draft again.
     */
    'page_statuses' => [
        'draft' => ['label' => 'Draft', 'color' => '#9ca3af'],
        'published' => ['label' => 'Published', 'color' => '#22c55e'],
    ],

    'page_default_status' => 'draft',

    /**
     * Jodit Pro licence key.
     *
     * The Pro plugins register but stay inert without it — which looks exactly like "the Pro
     * features are missing". Kept in the environment rather than the repo because it is a
     * purchased credential, not configuration.
     */
    'jodit_license' => env('JODIT_LICENSE', ''),

    /**
     * How long consecutive saves by one author fold into a single version.
     *
     * The editor autosaves after every pause in typing; without a window, an afternoon's work
     * becomes hundreds of near-identical versions and the history stops being useful for the
     * one thing it is for — finding the state to go back to.
     *
     * Short enough that separate sittings show up as separate versions, long enough that
     * writing one section is one entry. Measured from the START of the version, so it closes
     * on time however long the editing continues.
     */
    'page_version_window_minutes' => 5,

    /** Versions kept per page. Each one carries a full copy of the document. */
    'page_versions_max' => 50,

    /** Pages (§8). A title is a heading, not a document. */
    'page_title_max' => 200,

    /** Rich-text body, the same ceiling a work item description gets. */
    'page_content_max' => 200000,

    /** §7: a label name is short by design; the description carries any nuance. */
    'label_name_max' => 50,

    'label_description_max' => 255,

    'estimate_label_max' => 40,

    /** §10: enough values to be useful, few enough to stay a picker rather than a list. */
    'estimate_values_max' => 20,

    /** Cycles (sprints) — Cycles §6.1. */
    'cycle_name_max' => 120,

    'cycle_description_max' => 2000,
];
