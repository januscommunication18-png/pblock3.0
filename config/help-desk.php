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
            'abilities' => ['view', 'reply', 'note', 'assign', 'manage_inboxes', 'manage_members', 'manage_settings'],
        ],
        'manager' => [
            'label' => 'Manager',
            'description' => 'Team operations, workload, assignment and reports.',
            'rank' => 40,
            'all_inboxes' => true,
            'abilities' => ['view', 'reply', 'note', 'assign'],
        ],
        'agent' => [
            'label' => 'Agent',
            'description' => 'Handles customer conversations in the inboxes they are given.',
            'rank' => 30,
            'all_inboxes' => false,
            'abilities' => ['view', 'reply', 'note'],
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
