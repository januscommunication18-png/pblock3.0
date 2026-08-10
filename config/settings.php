<?php

/*
|--------------------------------------------------------------------------
| Workspace Settings (Phase 3 — Settings)
|--------------------------------------------------------------------------
| Single source of truth for the Settings module: the left-nav model, feature
| toggle defaults, seeded project states, the color-preset palette, customer
| property types and the immutable default customer property set. Mirrors the
| HTML POC (setting-*.html) and is enforced server-side (spec §13/§14).
*/

return [

    /**
     * Persistent left-side navigation (doc §13). `key` maps to a settings route/section;
     * `status` is one of: active (implemented), soon (Coming Soon — disabled), placeholder
     * (nav presence only, not a Phase 3 deliverable).
     */
    'nav' => [
        'Administration' => [
            ['key' => 'general', 'label' => 'General', 'status' => 'active'],
            ['key' => 'members', 'label' => 'Members', 'status' => 'active'],
            ['key' => 'billing', 'label' => 'Billing and plans', 'status' => 'placeholder'],
            ['key' => 'imports', 'label' => 'Imports', 'status' => 'placeholder'],
            ['key' => 'exports', 'label' => 'Exports', 'status' => 'placeholder'],
        ],
        'Products' => [
            ['key' => 'wiki', 'label' => 'Wiki', 'status' => 'active'],
            ['key' => 'ai', 'label' => 'Project Block AI', 'status' => 'placeholder'],
        ],
        'Features' => [
            ['key' => 'projects', 'label' => 'Projects', 'status' => 'active'],
            ['key' => 'teamspaces', 'label' => 'Teamspaces', 'status' => 'active'],
            ['key' => 'initiatives', 'label' => 'Initiatives', 'status' => 'active'],
            ['key' => 'customers', 'label' => 'Customers', 'status' => 'active'],
            ['key' => 'templates', 'label' => 'Templates', 'status' => 'soon'],
            ['key' => 'integrations', 'label' => 'Integrations', 'status' => 'soon'],
            ['key' => 'connections', 'label' => 'Connections', 'status' => 'placeholder'],
            ['key' => 'releases', 'label' => 'Releases', 'status' => 'active'],
        ],
        'Developers' => [
            ['key' => 'webhooks', 'label' => 'Webhooks', 'status' => 'placeholder'],
            ['key' => 'tokens', 'label' => 'Access Tokens', 'status' => 'placeholder'],
        ],
    ],

    /**
     * Default feature-toggle values for a freshly provisioned workspace_settings row.
     * POC defaults: Releases on, Initiatives off, Teamspaces off, Customers on, Wiki on,
     * Project States on.
     */
    'feature_defaults' => [
        'project_states_enabled' => true,
        'releases_enabled' => true,
        'initiatives_enabled' => false,
        'teamspaces_enabled' => false,
        'customers_enabled' => true,
        'wiki_enabled' => false,
    ],

    /**
     * Curated color palette offered by every color picker (setting-projects PRESETS).
     * Pickers also accept any valid #RRGGBB hex (spec §13).
     */
    'color_presets' => [
        '#F97316', '#F59E0B', '#4ADE80', '#22C55E', '#7DD3FC',
        '#2563EB', '#9CA3AF', '#DC2626', '#F78DA7', '#7C3AED',
    ],

    /**
     * Project-state lifecycle groups (visual grouping in the state editor), in order.
     */
    'state_groups' => [
        'backlog' => 'Backlog',
        'unstarted' => 'Unstarted',
        'started' => 'Started',
        'active' => 'Active',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ],

    /**
     * Seeded default project states (setting-projects STATES). Draft is the default.
     * Seeded once when a workspace's settings are first provisioned; thereafter editable.
     */
    'default_project_states' => [
        ['name' => 'Draft', 'color' => '#60646C', 'group' => 'backlog', 'is_default' => true],
        ['name' => 'Planning', 'color' => '#6B7280', 'group' => 'unstarted', 'is_default' => false],
        ['name' => 'Execution', 'color' => '#F97316', 'group' => 'started', 'is_default' => false],
        ['name' => 'Monitoring', 'color' => '#14B8A6', 'group' => 'active', 'is_default' => false],
        ['name' => 'Completed', 'color' => '#22C55E', 'group' => 'completed', 'is_default' => false],
        ['name' => 'Cancelled', 'color' => '#9CA3AF', 'group' => 'cancelled', 'is_default' => false],
    ],

    /**
     * Default project priorities (Settings → Projects → Priority). Seeded once when a
     * workspace's settings are first provisioned; thereafter editable/renamable.
     */
    'default_project_priorities' => [
        ['name' => 'Urgent', 'color' => '#EF4444'],
        ['name' => 'High', 'color' => '#F97316'],
        ['name' => 'Medium', 'color' => '#EAB308'],
        ['name' => 'Low', 'color' => '#3B82F6'],
        ['name' => 'None', 'color' => '#94A3B8'],
    ],

    /**
     * Customer custom-property types (setting-customers TYPES).
     */
    'customer_property_types' => ['Text', 'Number', 'Date', 'Dropdown', 'Checkbox', 'Member', 'URL', 'Email'],

    /**
     * Immutable/system default customer properties (setting-customers DEFAULTS). Display-only;
     * never stored or edited (spec SET-CUST-002).
     */
    'default_customer_properties' => [
        ['title' => 'Customer name', 'type' => 'Text'],
        ['title' => 'Description', 'type' => 'Text'],
        ['title' => 'Email', 'type' => 'Email'],
        ['title' => 'Website', 'type' => 'URL'],
        ['title' => 'Employees', 'type' => 'Number'],
        ['title' => 'Industry', 'type' => 'Text'],
        ['title' => 'Stage', 'type' => 'Dropdown'],
        ['title' => 'Contract Status', 'type' => 'Dropdown'],
        ['title' => 'Revenue', 'type' => 'Number'],
    ],

    /**
     * Authentication methods surfaced in the Members table (setting-member AUTH). Display map.
     */
    'auth_methods' => ['magic' => 'Magic code', 'google' => 'Google', 'github' => 'GitHub', 'sso' => 'SSO'],

    /**
     * Logo upload constraints (SET-G-006).
     */
    'logo' => [
        'max_kb' => 2048,
        'mimes' => ['jpg', 'jpeg', 'png', 'svg', 'webp'],
    ],
];
